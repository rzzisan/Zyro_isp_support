package com.zyrotechbd.desk.printing

import android.graphics.Bitmap
import android.graphics.Color
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class EscPosEncoderTest {
    @Test
    fun blankRowsUnderTheReceiptAreNotPrinted() {
        val bitmap = Bitmap.createBitmap(384, 500, Bitmap.Config.ARGB_8888).apply { eraseColor(Color.WHITE) }
        bitmap.setPixel(10, 119, Color.BLACK)
        assertEquals(120, EscPosEncoder.inkHeight(bitmap))
        // init + one band header + 120 rows of 48 bytes + 3 line feeds + cut
        assertEquals(2 + 8 + 120 * 48 + 3 + 3, EscPosEncoder.bitmapToEscPos(bitmap).size)
    }
}
