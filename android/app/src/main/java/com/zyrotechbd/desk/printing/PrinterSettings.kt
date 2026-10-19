package com.zyrotechbd.desk.printing

import android.content.Context
import com.zyrotechbd.desk.core.AppContext

object PrinterSettings {
    private const val PREFS_NAME = "printer_settings"
    private const val KEY_TYPE = "printer_type"
    private const val KEY_ADDRESS = "printer_address"
    private const val KEY_BLE_SERVICE = "ble_service_uuid"
    private const val KEY_BLE_CHARACTERISTIC = "ble_characteristic_uuid"
    private const val KEY_PAPER = "paper_mm"

    private val prefs by lazy {
        AppContext.app.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    fun getPrinterType(): PrinterType {
        val stored = prefs.getString(KEY_TYPE, PrinterType.CLASSIC.name)
        return runCatching { PrinterType.valueOf(stored ?: PrinterType.CLASSIC.name) }
            .getOrDefault(PrinterType.CLASSIC)
    }

    fun setPrinterType(type: PrinterType) {
        prefs.edit().putString(KEY_TYPE, type.name).apply()
    }

    fun getPrinterAddress(): String? = prefs.getString(KEY_ADDRESS, null)

    fun setPrinterAddress(address: String) {
        prefs.edit().putString(KEY_ADDRESS, address).apply()
    }

    /** Paper roll width in mm: 58 (384 dots) or 80 (576 dots). */
    fun getPaperWidth(): Int = prefs.getInt(KEY_PAPER, 58).let { if (it == 80) 80 else 58 }

    fun setPaperWidth(mm: Int) {
        prefs.edit().putInt(KEY_PAPER, if (mm == 80) 80 else 58).apply()
    }

    fun printDots(): Int = if (getPaperWidth() == 80) 576 else 384

    fun getBleServiceUuid(): String {
        return prefs.getString(KEY_BLE_SERVICE, DEFAULT_SERVICE_UUID) ?: DEFAULT_SERVICE_UUID
    }

    fun getBleCharacteristicUuid(): String {
        return prefs.getString(KEY_BLE_CHARACTERISTIC, DEFAULT_CHARACTERISTIC_UUID)
            ?: DEFAULT_CHARACTERISTIC_UUID
    }

    fun setBleUuids(serviceUuid: String, characteristicUuid: String) {
        prefs.edit()
            .putString(KEY_BLE_SERVICE, serviceUuid)
            .putString(KEY_BLE_CHARACTERISTIC, characteristicUuid)
            .apply()
    }

    private const val DEFAULT_SERVICE_UUID = "0000ffe0-0000-1000-8000-00805f9b34fb"
    private const val DEFAULT_CHARACTERISTIC_UUID = "0000ffe1-0000-1000-8000-00805f9b34fb"
}

enum class PrinterType {
    CLASSIC,
    BLE
}
