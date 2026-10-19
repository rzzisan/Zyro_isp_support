package com.zyrotechbd.desk.ui.screens

import android.annotation.SuppressLint
import android.app.Activity
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothManager
import android.bluetooth.le.ScanCallback
import android.bluetooth.le.ScanResult
import android.content.Context
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Print
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.core.BluetoothPermissions
import com.zyrotechbd.desk.printing.PrinterManager
import com.zyrotechbd.desk.printing.PrinterSettings
import com.zyrotechbd.desk.printing.PrinterType
import com.zyrotechbd.desk.ui.components.CatvCard
import com.zyrotechbd.desk.ui.components.CatvTextField
import com.zyrotechbd.desk.ui.components.Chip
import com.zyrotechbd.desk.ui.components.EmptyText
import com.zyrotechbd.desk.ui.components.Good
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.components.SectionTitle
import com.zyrotechbd.desk.ui.components.SmallAction
import com.zyrotechbd.desk.ui.components.Warn
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.Muted
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Pick the Bluetooth receipt printer (paired Classic, or BLE by scan), the paper width, and print a test page. */
@SuppressLint("MissingPermission")
@Composable
fun PrintersScreen() {
    val s = LocalStrings.current
    val context = LocalContext.current
    val activity = context as Activity
    val adapter = remember { (context.getSystemService(Context.BLUETOOTH_SERVICE) as? BluetoothManager)?.adapter }
    val scope = rememberCoroutineScope()
    var permissionTick by remember { mutableIntStateOf(0) }
    var printerType by remember { mutableStateOf(PrinterSettings.getPrinterType()) }
    var selected by remember { mutableStateOf(PrinterSettings.getPrinterAddress()) }
    var paper by remember { mutableIntStateOf(PrinterSettings.getPaperWidth()) }
    var bleService by remember { mutableStateOf(PrinterSettings.getBleServiceUuid()) }
    var bleCharacteristic by remember { mutableStateOf(PrinterSettings.getBleCharacteristicUuid()) }
    var scanning by remember { mutableStateOf(false) }
    val scanResults = remember { mutableStateListOf<BluetoothDevice>() }
    var status by remember { mutableStateOf<String?>(null) }
    var testing by remember { mutableStateOf(false) }
    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        permissionTick++
    }

    val scanCallback = remember {
        object : ScanCallback() {
            override fun onScanResult(callbackType: Int, result: ScanResult) {
                if (scanResults.none { it.address == result.device.address }) scanResults.add(result.device)
            }
        }
    }
    LaunchedEffect(scanning) {
        if (scanning) {
            delay(10_000)
            runCatching { adapter?.bluetoothLeScanner?.stopScan(scanCallback) }
            scanning = false
        }
    }
    DisposableEffect(Unit) {
        onDispose { if (scanning) runCatching { adapter?.bluetoothLeScanner?.stopScan(scanCallback) } }
    }

    fun choose(device: BluetoothDevice, type: PrinterType) {
        selected = device.address
        PrinterSettings.setPrinterType(type)
        PrinterSettings.setPrinterAddress(device.address)
        if (type == PrinterType.BLE) PrinterSettings.setBleUuids(bleService, bleCharacteristic)
        status = null
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
        if (adapter == null) {
            MessageBox(s.bluetoothNotSupported)
            return@Column
        }
        val missing = remember(permissionTick) { BluetoothPermissions.missingPermissions(context) }
        if (missing.isNotEmpty()) {
            MessageBox(s.bluetoothPermRequired, color = Warn)
            Spacer(Modifier.height(12.dp))
            PrimaryButton(s.grantPermissionBtn) { permissionLauncher.launch(missing.toTypedArray()) }
            return@Column
        }
        if (!adapter.isEnabled) {
            MessageBox(s.bluetoothDisabled, color = Warn, onRetry = { permissionTick++ }, retryLabel = s.retry)
            return@Column
        }

        SectionTitle(s.paperWidth)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(58, 80).forEach { mm ->
                Chip("$mm mm", paper == mm) {
                    paper = mm
                    PrinterSettings.setPaperWidth(mm)
                }
            }
        }
        Text(s.paperHint, fontSize = 12.sp, color = Muted, modifier = Modifier.padding(top = 4.dp))
        Spacer(Modifier.height(14.dp))

        SectionTitle(s.printerKind)
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Chip(s.printerClassic, printerType == PrinterType.CLASSIC) { printerType = PrinterType.CLASSIC }
            Chip("BLE", printerType == PrinterType.BLE) { printerType = PrinterType.BLE }
        }
        Spacer(Modifier.height(10.dp))

        CatvCard {
            Column(Modifier.padding(horizontal = 14.dp, vertical = 6.dp)) {
                if (printerType == PrinterType.CLASSIC) {
                    val devices = remember(permissionTick) { adapter.bondedDevices?.toList().orEmpty() }
                    if (devices.isEmpty()) EmptyText(s.noPairedPrinters)
                    else Text(s.pairedDevicesLabel, fontSize = 12.sp, color = Muted, modifier = Modifier.padding(vertical = 6.dp))
                    devices.forEachIndexed { i, d ->
                        if (i > 0) HorizontalDivider(color = Color(0xFFF0EDE8))
                        DeviceRow(d.name ?: d.address, d.address, selected == d.address && PrinterSettings.getPrinterType() == PrinterType.CLASSIC,
                            s.printerSelect, s.printerSelected) { choose(d, PrinterType.CLASSIC) }
                    }
                } else {
                    val scanner = adapter.bluetoothLeScanner
                    if (scanner == null) {
                        EmptyText(s.bleNotSupported)
                    } else {
                        Spacer(Modifier.height(6.dp))
                        PrimaryButton(if (scanning) s.scanningLabel else s.scanBleBtn, enabled = !scanning) {
                            scanResults.clear()
                            runCatching { scanner.startScan(scanCallback) }
                            scanning = true
                        }
                        scanResults.forEachIndexed { i, d ->
                            if (i > 0) HorizontalDivider(color = Color(0xFFF0EDE8))
                            DeviceRow(d.name ?: d.address, d.address, selected == d.address && PrinterSettings.getPrinterType() == PrinterType.BLE,
                                s.printerSelect, s.printerSelected) { choose(d, PrinterType.BLE) }
                        }
                        Spacer(Modifier.height(10.dp))
                        Text("BLE Service UUID", fontSize = 12.sp, color = Muted)
                        CatvTextField(bleService, { bleService = it.trim() })
                        Spacer(Modifier.height(6.dp))
                        Text("BLE Characteristic UUID", fontSize = 12.sp, color = Muted)
                        CatvTextField(bleCharacteristic, { bleCharacteristic = it.trim() })
                        Spacer(Modifier.height(8.dp))
                        SmallAction(s.saveUuidsBtn, Icons.Filled.CheckCircle) {
                            PrinterSettings.setBleUuids(bleService, bleCharacteristic)
                            status = s.saved
                        }
                        Spacer(Modifier.height(8.dp))
                    }
                }
            }
        }
        Spacer(Modifier.height(16.dp))
        PrimaryButton(s.testPrint, enabled = selected != null, loading = testing) {
            scope.launch {
                testing = true
                status = s.printingMsg
                status = PrinterManager.printTest(activity, s) ?: s.printSuccessMsg
                testing = false
            }
        }
        status?.let {
            Spacer(Modifier.height(10.dp))
            Text(it, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                color = if (it == s.printSuccessMsg || it == s.saved) Good else if (it == s.printingMsg) Ink else Warn)
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun DeviceRow(name: String, address: String, isSelected: Boolean, select: String, selectedLabel: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable { onClick() }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Filled.Print, contentDescription = null, tint = if (isSelected) Good else Muted)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(name, fontSize = 14.sp, color = Ink, fontWeight = FontWeight.SemiBold)
            Text(address, fontSize = 11.sp, color = Muted)
        }
        Text(if (isSelected) selectedLabel else select, fontSize = 13.sp, fontWeight = FontWeight.Bold,
            color = if (isSelected) Good else Muted)
    }
}
