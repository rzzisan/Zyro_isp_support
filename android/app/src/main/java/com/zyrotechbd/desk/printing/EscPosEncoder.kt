package com.zyrotechbd.desk.printing

import android.graphics.Bitmap

object EscPosEncoder {
    /** Rows per raster command: small bands fit the buffer of cheap printers, a long receipt prints in pieces. */
    private const val BAND = 256

    fun bitmapToEscPos(bitmap: Bitmap): ByteArray {
        val width = bitmap.width
        val height = bitmap.height
        val bytesPerRow = (width + 7) / 8
        val pixels = IntArray(width)
        val out = java.io.ByteArrayOutputStream()
        out.write(byteArrayOf(0x1B, 0x40))  // init

        var top = 0
        while (top < height) {
            val rows = minOf(BAND, height - top)
            out.write(byteArrayOf(0x1D, 0x76, 0x30, 0x00,
                (bytesPerRow and 0xFF).toByte(), ((bytesPerRow shr 8) and 0xFF).toByte(),
                (rows and 0xFF).toByte(), ((rows shr 8) and 0xFF).toByte()))
            val band = ByteArray(bytesPerRow * rows)
            for (r in 0 until rows) {
                bitmap.getPixels(pixels, 0, width, 0, top + r, width, 1)
                for (x in 0 until width) {
                    val p = pixels[x]
                    val luminance = ((p shr 16) and 0xFF) * 0.299 + ((p shr 8) and 0xFF) * 0.587 + (p and 0xFF) * 0.114
                    if (luminance < 160) {
                        val i = r * bytesPerRow + x / 8
                        band[i] = (band[i].toInt() or (1 shl (7 - x % 8))).toByte()
                    }
                }
            }
            out.write(band)
            top += rows
        }
        // Feed just enough (~3 lines ≈ 12mm) to push the printed end past the tear bar (CATV: a larger feed left
        // ~2 inch of blank paper), then cut where the printer has a cutter.
        out.write(ByteArray(3) { 0x0A.toByte() })
        out.write(byteArrayOf(0x1D, 0x56, 0x01))
        return out.toByteArray()
    }
}
