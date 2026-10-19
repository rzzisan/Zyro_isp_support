package com.zyrotechbd.desk

import androidx.activity.ComponentActivity
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.test.hasScrollToIndexAction
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.performScrollToIndex
import androidx.navigation.compose.rememberNavController
import com.zyrotechbd.desk.data.auth.SessionStore
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.CompanyInfo
import com.zyrotechbd.desk.ui.screens.CustomersScreen
import com.zyrotechbd.desk.ui.strings.BengaliStrings
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.DeskTheme
import org.junit.After
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Scrolling to the end of the customer list loads the next page (1.5.0 cancelled its own request and showed an error). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w393dp-h852dp-xxhdpi")
class CustomerPagingTest {
    @get:Rule val rule = createAndroidComposeRule<ComponentActivity>()

    private val desk = FakeDesk()

    private fun row(n: Int) = """{"header_id":$n,"customer_id":"$n","name":"Cust $n","username":"u$n","due":0,"online":false}"""

    @Before
    fun start() {
        desk.customerPage = { page ->
            if (page == 1) """{"data":[${(1..50).joinToString(",") { row(it) }}],"next_page":2}"""
            else """{"data":[${(51..60).joinToString(",") { row(it) }}],"next_page":null}"""
        }
        desk.start(delayMs = 300)
        val c = ApiClient.moshi.adapter(CompanyInfo::class.java).fromJson(desk.company)!!
        SessionStore.save("t", "Zisan", "z@x.com", listOf(c))
        SessionStore.selectCompany(c.slug)
    }

    @After
    fun stop() = desk.stop()

    private fun list() = rule.onAllNodes(hasScrollToIndexAction())[1]  // [0] is the filter chips

    private fun shown(text: String) = rule.onAllNodesWithText(text).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun scrollingToTheEndLoadsTheNextPage() {
        rule.setContent {
            DeskTheme { CompositionLocalProvider(LocalStrings provides BengaliStrings) {
                CustomersScreen(rememberNavController(), "cln", collect = false)
            } }
        }
        rule.waitUntil(10_000) { shown("Cust 1") }
        list().performScrollToIndex(49)
        rule.waitUntil(10_000) { desk.paths.count { it.endsWith("/customers") } >= 2 }
        rule.waitUntil(10_000) { runCatching { list().performScrollToIndex(59) }.isSuccess && shown("Cust 60") }
        assertTrue(!shown(BengaliStrings.loadFailed))
    }
}
