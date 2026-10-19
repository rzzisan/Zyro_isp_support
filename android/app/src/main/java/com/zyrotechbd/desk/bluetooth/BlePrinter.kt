package com.zyrotechbd.desk.bluetooth

import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothGatt
import android.bluetooth.BluetoothGattCallback
import android.bluetooth.BluetoothGattCharacteristic
import android.bluetooth.BluetoothProfile
import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.UUID
import kotlin.coroutines.resume
import kotlin.coroutines.suspendCoroutine

class BlePrinter {
    suspend fun print(
        context: Context,
        device: BluetoothDevice,
        serviceUuid: UUID,
        characteristicUuid: UUID,
        data: ByteArray
    ): Boolean = withContext(Dispatchers.IO) {
        suspendCoroutine { continuation ->
            val chunks = data.toList().chunked(CHUNK_SIZE).map { it.toByteArray() }.toMutableList()
            var gatt: BluetoothGatt? = null
            var characteristic: BluetoothGattCharacteristic? = null

            val callback = object : BluetoothGattCallback() {
                override fun onConnectionStateChange(gatt: BluetoothGatt, status: Int, newState: Int) {
                    if (status != BluetoothGatt.GATT_SUCCESS) {
                        gatt.close()
                        continuation.resume(false)
                        return
                    }
                    if (newState == BluetoothProfile.STATE_CONNECTED) {
                        gatt.discoverServices()
                    } else if (newState == BluetoothProfile.STATE_DISCONNECTED) {
                        gatt.close()
                    }
                }

                override fun onServicesDiscovered(gatt: BluetoothGatt, status: Int) {
                    if (status != BluetoothGatt.GATT_SUCCESS) {
                        gatt.close()
                        continuation.resume(false)
                        return
                    }
                    val service = gatt.getService(serviceUuid)
                    characteristic = service?.getCharacteristic(characteristicUuid)
                    if (characteristic == null) {
                        gatt.close()
                        continuation.resume(false)
                        return
                    }
                    writeNextChunk(gatt)
                }

                override fun onCharacteristicWrite(
                    gatt: BluetoothGatt,
                    characteristic: BluetoothGattCharacteristic,
                    status: Int
                ) {
                    if (status != BluetoothGatt.GATT_SUCCESS) {
                        gatt.close()
                        continuation.resume(false)
                        return
                    }
                    if (chunks.isEmpty()) {
                        gatt.close()
                        continuation.resume(true)
                    } else {
                        writeNextChunk(gatt)
                    }
                }

                private fun writeNextChunk(gatt: BluetoothGatt) {
                    val target = characteristic ?: run {
                        gatt.close()
                        continuation.resume(false)
                        return
                    }
                    val chunk = chunks.removeAt(0)
                    target.writeType = BluetoothGattCharacteristic.WRITE_TYPE_DEFAULT
                    target.value = chunk
                    val started = gatt.writeCharacteristic(target)
                    if (!started) {
                        gatt.close()
                        continuation.resume(false)
                    }
                }
            }

            gatt = device.connectGatt(context, false, callback)
        }
    }

    private companion object {
        const val CHUNK_SIZE = 180
    }
}
