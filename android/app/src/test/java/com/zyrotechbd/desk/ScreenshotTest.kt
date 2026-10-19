package com.zyrotechbd.desk

import android.graphics.Bitmap
import android.graphics.Canvas
import androidx.activity.ComponentActivity
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.test.captureToImage
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onRoot
import androidx.navigation.compose.rememberNavController
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.CompanyInfo
import com.zyrotechbd.desk.ui.screens.BillFormScreen
import com.zyrotechbd.desk.ui.screens.BillsScreen
import com.zyrotechbd.desk.ui.screens.CustomerDetailScreen
import com.zyrotechbd.desk.ui.screens.CustomersScreen
import com.zyrotechbd.desk.ui.screens.DashboardScreen
import com.zyrotechbd.desk.ui.screens.LoginScreen
import com.zyrotechbd.desk.ui.screens.MoreScreen
import com.zyrotechbd.desk.ui.screens.PrintersScreen
import com.zyrotechbd.desk.ui.strings.BengaliStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.DeskTheme
import androidx.compose.foundation.background
import org.junit.After
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.io.File
import android.bluetooth.BluetoothManager
import android.content.Context
import com.zyrotechbd.desk.core.BluetoothPermissions
import org.robolectric.Shadows.shadowOf

/**
 * Draws each screen with the fake Desk's data and saves a PNG (to look at a design without a phone). Pass
 * -Pscreens=<dir> to choose the folder; by default the PNGs go under build/.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-xxhdpi")
class ScreenshotTest {
    @get:Rule val rule = createAndroidComposeRule<ComponentActivity>()

    private val desk = FakeDesk()

    @Before
    fun start() {
        desk.start(delayMs = 0)
        val c = ApiClient.moshi.adapter(CompanyInfo::class.java).fromJson(desk.company)!!
        SessionStore.save("t", "Zisan", "z@x.com", listOf(c))
        SessionStore.selectCompany(c.slug)
    }

    @After
    fun stop() = desk.stop()

    private fun shot(name: String, waitFor: String, content: @Composable () -> Unit) {
        rule.setContent {
            DeskTheme {
                CompositionLocalProvider(LocalStrings provides BengaliStrings) {
                    Box(Modifier.fillMaxSize().background(Background)) { content() }
                }
            }
        }
        val found = runCatching {
            rule.waitUntil(10_000) { rule.onAllNodesWithText(waitFor, substring = true, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty() }
        }.isSuccess
        rule.mainClock.advanceTimeBy(1_000)
        rule.waitForIdle()
        val dir = File(System.getProperty("screens.dir") ?: "build/screens").apply { mkdirs() }
        val view = rule.activity.window.decorView
        val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
        rule.runOnUiThread { view.draw(Canvas(bitmap)) }
        File(dir, "$name.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
        check(found) { "\"$waitFor\" never showed on $name; see $name.png and requests ${desk.paths}" }
    }

    @Test fun dashboard() = shot("1-dashboard", "৳1,500") { DashboardScreen(rememberNavController(), "cln") }
    @Test fun customers() = shot("2-customers", "Rahima") { CustomersScreen(rememberNavController(), "cln", collect = false) }
    @Test fun customer() = shot("3-customer", "-21.5") { CustomerDetailScreen(rememberNavController(), "cln", 3) }
    @Test fun billForm() = shot("4-bill-form", "en.enamul") { BillFormScreen(rememberNavController(), "cln", 3) }
    @Test fun bills() = shot("5-bills", "Rahima") { BillsScreen("cln") }
    @Test fun login() = shot("0-login", "Zyro Desk") {
        LoginScreen("z@x.com", "", false, null, {}, {}, {}, {})
    }
    @Test fun more() = shot("7-more", "Zisan") {
        MoreScreen(SessionStore.company(), SessionStore.companies(), {}, {}, {}, {}, {})
    }
    @Test fun printers() {
        val app = rule.activity.application
        shadowOf(app).grantPermissions(*BluetoothPermissions.missingPermissions(app).toTypedArray())
        shadowOf((app.getSystemService(Context.BLUETOOTH_SERVICE) as BluetoothManager).adapter).setEnabled(true)
        shot("6-printers", "কাগজের মাপ") { PrintersScreen() }
    }
}
