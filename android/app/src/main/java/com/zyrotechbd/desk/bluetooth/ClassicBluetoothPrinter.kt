package com.zyrotechbd.desk.bluetooth

import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.IOException
import java.util.UUID

class ClassicBluetoothPrinter {
    suspend fun connect(device: BluetoothDevice): BluetoothSocket? {
        return withContext(Dispatchers.IO) {
            try {
                val socket = device.createRfcommSocketToServiceRecord(SPP_UUID)
                socket.connect()
                socket
            } catch (error: IOException) {
                null
            }
        }
    }

    suspend fun print(socket: BluetoothSocket, data: ByteArray) {
        withContext(Dispatchers.IO) {
            socket.outputStream.write(data)
            socket.outputStream.flush()
        }
    }

    suspend fun disconnect(socket: BluetoothSocket) {
        withContext(Dispatchers.IO) {
            try {
                socket.close()
            } catch (_: IOException) {
            }
        }
    }

    private companion object {
        val SPP_UUID: UUID = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
    }
}
