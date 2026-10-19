package com.zyrotechbd.desk

import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.zyrotechbd.desk.data.network.ApiClient
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest
import org.junit.After
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Signs in against a fake Desk API; the dashboard recomposes when its numbers arrive (this crashed after login in 1.0.0). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], qualifiers = "w411dp-h891dp-xxhdpi")
class AppFlowTest {
    @get:Rule val rule = createAndroidComposeRule<MainActivity>()

    private val server = MockWebServer()

    private val company = """{"id":1,"slug":"cln","name":"Century Link Network","role":"owner",
        "menus":["inbox","customers","bills","tickets","monitoring"]}"""
    private val customer = """{"header_id":3,"customer_id":"0003","name":"Bulbul","mobile":"01714080210",
        "username":"en.enamul","zone":"Tilardi","package":"10 Mbps","monthly_bill":525,"due":525,"status":"Active",
        "disabled":false,"is_left":false,"online":true}"""

    private fun json(body: String) = MockResponse().setHeader("Content-Type", "application/json").setBody(body)

    @Before
    fun start() {
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                val path = request.path.orEmpty().removePrefix("/api/v1/").substringBefore("?")
                Thread.sleep(150)  // data lands after the first frame, like on a phone
                return when {
                    path == "login" -> json("""{"token":"t","user":{"id":1,"name":"Zisan","email":"z@x.com"},"companies":[$company]}""")
                    path == "me" -> json("""{"user":{"id":1,"name":"Zisan","email":"z@x.com"},"companies":[$company]}""")
                    path.endsWith("/dashboard") -> json("""{"bills":{"count":3,"paid":1500,"pending":300,"mine_count":1,"mine_paid":525},
                        "tickets":{"open":4,"opened_today":2,"solved_today":1},"network":{"online":900,"active_customers":4800},
                        "inbox":{"waiting":2}}""")
                    path.endsWith("/zones") -> json("""{"data":["Tilardi"]}""")
                    path.endsWith("/customers") -> json("""{"data":[$customer],"next_page":null}""")
                    path.endsWith("/customers/3") -> json("""{"customer":$customer,"payments":[{"date":"2026-09-10","paid":525,
                        "discount":0,"due":0,"method":"Cash"}],"tickets":[],"can":{"bill":true}}""")
                    path.endsWith("/live") -> json("""{"mikrotik":{"online":true,"uptime":"3d","address":"10.0.0.3"},
                        "onu":{"OpticalPower":"-21.5","OnuStatus":"Online"},"mac":"AA:BB:CC:DD:EE:FF"}""")
                    path.endsWith("/bill-form") -> json("""{"username":"en.enamul","status":"Active","monthly":525,"payable":525,
                        "bill_month":"October","methods":[{"id":"71","name":"Cash"}],"method_default":"71",
                        "received_by":[{"id":"1","name":"Zisan"}],"received_by_default":"1","pending":[],"blocked":null,
                        "sms_ready":false,"sms_default":false}""")
                    path.endsWith("/daily-bills") -> json("""{"totals":{"count":1,"paid":525,"discount":0,"due":0},"data":[
                        {"daily_bill_id":5,"customer_id":"0003","customer_name":"Bulbul","paid":525,"status":"pending"}],"next_page":null}""")
                    path.endsWith("/collections") -> json("""{"data":[]}""")
                    else -> MockResponse().setResponseCode(404)
                }
            }
        }
        server.start()
        ApiClient.baseUrl = server.url("/api/v1/").toString()
    }

    @After
    fun stop() = server.shutdown()

    private fun waitForText(text: String) = rule.waitUntil(10_000) {
        rule.onAllNodesWithText(text, substring = true).fetchSemanticsNodes().isNotEmpty()
    }

    @Test
    fun signInAndSeeTheDashboard() {
        val fields = rule.onAllNodes(hasSetTextAction())
        fields[0].performTextInput("z@x.com")
        fields[1].performTextInput("secret")
        rule.onAllNodes(hasText("লগইন")).onFirst().performScrollTo().performClick()

        waitForText("আমার কালেকশন")      // dashboard numbers arrived and recomposed
        waitForText("৳1,500")
    }
}
