package com.zyrotechbd.desk

import com.zyrotechbd.desk.data.network.ApiClient
import okhttp3.mockwebserver.Dispatcher
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.RecordedRequest

/** A fake Desk API (/api/v1) with one company and one customer, for the app's Robolectric tests. */
class FakeDesk {
    private val server get() = shared

    val company = """{"id":1,"slug":"cln","name":"Century Link Network","role":"owner",
        "menus":["inbox","customers","bills","tickets","monitoring"]}"""
    private val customer = """{"header_id":3,"customer_id":"0003","name":"Bulbul Ahmed","mobile":"01714080210",
        "username":"en.enamul","zone":"Tilardi","subzone":"Bazar","package":"10 Mbps","monthly_bill":525,"due":525,
        "status":"Active","disabled":false,"is_left":false,"online":true,"last_payment_date":"2026-09-10"}"""
    private val customer2 = """{"header_id":4,"customer_id":"0004","name":"Rahima Khatun","mobile":"01811000000",
        "username":"rahima","zone":"Murma","package":"15 Mbps","monthly_bill":700,"due":0,"status":"Active",
        "disabled":false,"is_left":false,"online":false}"""

    private fun json(body: String) = MockResponse().setHeader("Content-Type", "application/json").setBody(body)

    /** Paths asked for so far, to tell "never asked" from "asked but not drawn" when a test fails. */
    val paths = java.util.Collections.synchronizedList(mutableListOf<String>())

    fun start(delayMs: Long = 150) {
        server.dispatcher = object : Dispatcher() {
            override fun dispatch(request: RecordedRequest): MockResponse {
                val path = request.path.orEmpty().removePrefix("/api/v1/").substringBefore("?")
                paths += path
                Thread.sleep(delayMs)  // data lands after the first frame, like on a phone
                return when {
                    path == "login" -> json("""{"token":"t","user":{"id":1,"name":"Zisan","email":"z@x.com"},"companies":[$company]}""")
                    path == "me" -> json("""{"user":{"id":1,"name":"Zisan","email":"z@x.com"},"companies":[$company]}""")
                    path.endsWith("/dashboard") -> json("""{"bills":{"count":3,"paid":1500,"pending":300,"mine_count":1,"mine_paid":525},
                        "tickets":{"open":4,"opened_today":2,"solved_today":1},"network":{"online":900,"active_customers":4800},
                        "inbox":{"waiting":2}}""")
                    path.endsWith("/zones") -> json("""{"data":["Tilardi","Murma"]}""")
                    path.endsWith("/customers") -> json("""{"data":[$customer,$customer2],"next_page":null}""")
                    path.endsWith("/customers/3") -> json("""{"customer":$customer,"payments":[{"date":"2026-09-10",
                        "bill_month":"September","paid":525,"discount":0,"due":0,"method":"Cash","received_by":"Arif"}],
                        "tickets":[],"can":{"bill":true}}""")
                    path.endsWith("/live") -> json("""{"mikrotik":{"online":true,"uptime":"3d 4h","address":"10.0.0.3",
                        "profile":"10M","router":"CLN-3"},"onu":{"OpticalPower":"-21.5","OnuStatus":"Online","OLTName":"CLN-VSOL-42",
                        "OLTPort":"PON 2"},"mac":"AA:BB:CC:DD:EE:FF"}""")
                    path.endsWith("/bill-form") -> json("""{"username":"en.enamul","status":"Active","monthly":525,"payable":525,
                        "bill_month":"October","methods":[{"id":"71","name":"Cash"},{"id":"72","name":"bKash"}],"method_default":"71",
                        "received_by":[{"id":"1","name":"Zisan"}],"received_by_default":"1","pending":[],"blocked":null,
                        "sms_ready":true,"sms_default":true}""")
                    path.endsWith("/daily-bills") -> json("""{"totals":{"count":2,"paid":1225,"discount":0,"due":0},"data":[
                        {"daily_bill_id":5,"customer_id":"0003","customer_name":"Bulbul Ahmed","username":"en.enamul",
                         "collected_at":"2026-10-19T05:10:00+00:00","paid":525,"method":"Cash","received_by":"Zisan","status":"pending"},
                        {"daily_bill_id":6,"customer_id":"0004","customer_name":"Rahima Khatun","username":"rahima",
                         "collected_at":"2026-10-19T04:00:00+00:00","paid":700,"method":"bKash","received_by":"Arif","status":"approved",
                         "receipt_no":"MR-1201"}],"next_page":null}""")
                    path.endsWith("/collections") -> json("""{"data":[]}""")
                    else -> MockResponse().setResponseCode(404)
                }
            }
        }
        ApiClient.baseUrl = server.url("/api/v1/").toString()
    }

    fun stop() = paths.clear()

    companion object {
        // One server for the whole test run: ApiClient builds its Retrofit service once, on the first base URL it sees.
        private val shared by lazy { MockWebServer().apply { start() } }
    }
}
