package com.zyrotechbd.desk.printing

import android.annotation.SuppressLint
import android.app.Activity
import android.bluetooth.BluetoothManager
import android.content.Context
import com.zyrotechbd.desk.bluetooth.BlePrinter
import com.zyrotechbd.desk.bluetooth.ClassicBluetoothPrinter
import com.zyrotechbd.desk.core.BluetoothPermissions
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.ui.strings.AppStrings
import java.util.UUID

/** Which receipt to print: a bill collected from the Desk/app, or any bill in billing's daily collection list. */
sealed class ReceiptRef {
    data class Collection(val id: Long) : ReceiptRef()
    data class DailyBill(val id: Long) : ReceiptRef()
}

object PrinterManager {
    /** Fetches the receipt page, draws it and sends it to the saved Bluetooth printer. Null when printed, else why not. */
    @SuppressLint("MissingPermission")
    suspend fun printReceipt(activity: Activity, ref: ReceiptRef, s: AppStrings): String? {
        val adapter = (activity.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter
            ?: return s.bluetoothNotSupported
        val address = PrinterSettings.getPrinterAddress() ?: return s.noPrinterSelected
        if (!BluetoothPermissions.hasConnectPermission(activity)) return s.bluetoothPermRequired
        if (!adapter.isEnabled) return s.bluetoothDisabled
        val company = SessionStore.company()?.slug ?: return s.loadFailed

        val paper = PrinterSettings.getPaperWidth()
        val html = try {
            when (ref) {
                is ReceiptRef.Collection -> ApiClient.service.collectionReceipt(company, ref.id, paper)
                is ReceiptRef.DailyBill -> ApiClient.service.dailyBillReceipt(company, ref.id, paper)
            }.string()
        } catch (e: Exception) {
            return ApiClient.errorMessage(e, s.receiptLoadFailed, s.offline)
        }
        return printHtml(activity, html, s)
    }

    /** A short Bangla + English page to check the printer, the paper width and the connection. */
    suspend fun printTest(activity: Activity, s: AppStrings): String? {
        val paper = PrinterSettings.getPaperWidth()
        val company = SessionStore.company()?.name ?: "Zyro Desk"
        val html = """<!doctype html><html><head><meta charset="utf-8"><style>
            body{margin:0;font-family:sans-serif;color:#000}.r{width:${if (paper == 80) 72 else 50}mm;text-align:center;font-size:13px}
            .b{border-top:1px dashed #000;margin:6px 0}</style></head><body><div class="r">
            <b style="font-size:16px">$company</b><div class="b"></div>
            <div>${s.testPrintLine}</div><div>Zyro Desk · ${paper}mm</div><div class="b"></div>
            <div style="text-align:left">|0123456789|ABCDEFGHIJ|০১২৩৪৫৬৭৮৯|</div><div class="b"></div></div></body></html>"""
        return printHtml(activity, html, s)
    }

    @SuppressLint("MissingPermission")
    private suspend fun printHtml(activity: Activity, html: String, s: AppStrings): String? {
        val adapter = (activity.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter
            ?: return s.bluetoothNotSupported
        val address = PrinterSettings.getPrinterAddress() ?: return s.noPrinterSelected
        if (!BluetoothPermissions.hasConnectPermission(activity)) return s.bluetoothPermRequired
        if (!adapter.isEnabled) return s.bluetoothDisabled
        val paper = PrinterSettings.getPaperWidth()
        val bitmap = try {
            ReceiptRenderer.render(activity, html, paper, PrinterSettings.printDots())
        } catch (e: Exception) {
            return s.receiptLoadFailed
        }
        val payload = EscPosEncoder.bitmapToEscPos(bitmap)
        bitmap.recycle()

        val device = adapter.getRemoteDevice(address)
        return when (PrinterSettings.getPrinterType()) {
            PrinterType.CLASSIC -> {
                val printer = ClassicBluetoothPrinter()
                val socket = printer.connect(device) ?: return s.printerConnectFailed
                try {
                    printer.print(socket, payload)
                    null
                } catch (e: Exception) {
                    s.printerConnectFailed
                } finally {
                    printer.disconnect(socket)
                }
            }
            PrinterType.BLE -> {
                val service = runCatching { UUID.fromString(PrinterSettings.getBleServiceUuid()) }.getOrNull()
                    ?: return s.printerConnectFailed
                val characteristic = runCatching { UUID.fromString(PrinterSettings.getBleCharacteristicUuid()) }.getOrNull()
                    ?: return s.printerConnectFailed
                if (BlePrinter().print(activity, device, service, characteristic, payload)) null else s.printerConnectFailed
            }
        }
    }
}
