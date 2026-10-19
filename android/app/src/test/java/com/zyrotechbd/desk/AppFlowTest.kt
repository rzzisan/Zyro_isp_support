package com.zyrotechbd.desk

import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import org.junit.After
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import android.graphics.Bitmap
import android.graphics.Canvas
import java.io.File

/** Signs in against a fake Desk API; the dashboard recomposes when its numbers arrive (this crashed after login in 1.0.0). */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-xxhdpi")
class AppFlowTest {
    @get:Rule val rule = createAndroidComposeRule<MainActivity>()

    private val desk = FakeDesk()

    @Before
    fun start() = desk.start()

    @After
    fun stop() = desk.stop()

    private fun waitForText(text: String) = rule.waitUntil(10_000) {
        rule.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun signInAndSeeTheDashboard() {
        val fields = rule.onAllNodes(hasSetTextAction())
        fields[0].performTextInput("z@x.com")
        fields[1].performTextInput("secret")
        rule.onAllNodes(hasText("লগইন") and hasClickAction()).onFirst().performScrollTo().performClick()

        waitForText("আমার কালেকশন")      // dashboard numbers arrived and recomposed
        waitForText("৳1,500")

        // the whole app (top bar and bottom tabs) for a look, when screenshots were asked for
        System.getProperty("screens.dir")?.let { dir ->
            rule.waitForIdle()
            val view = rule.activity.window.decorView
            val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
            rule.runOnUiThread { view.draw(Canvas(bitmap)) }
            File(dir, "8-app.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
        }
    }
}
