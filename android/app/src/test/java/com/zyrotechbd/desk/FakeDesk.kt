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
    private val ticket get() = """{"id":5,"complain_id":"57231","customer_name":"Bulbul Ahmed","customer_id":"0003","header_id":3,
        "username":"en.enamul","mobile":"01714080210","zone":"Tilardi","subzone":"Bazar","category":"Line Off","priority":"High",
        "state":"pending","person":"Maruf","description":"লাল বাতি জ্বলছে, সকাল থেকে নেট নেই","created_by":"Desk Bot",
        "opened_at":"$now","duration":"2 ঘণ্টা 5 মিনিট","open":true}"""
    private val customer2 = """{"header_id":4,"customer_id":"0004","name":"Rahima Khatun","mobile":"01811000000",
        "username":"rahima","zone":"Murma","package":"15 Mbps","monthly_bill":700,"due":0,"status":"Active",
        "disabled":false,"is_left":false,"online":false}"""

    private fun json(body: String) = MockResponse().setHeader("Content-Type", "application/json").setBody(body)

    /** Paths asked for so far, to tell "never asked" from "asked but not drawn" when a test fails. */
    val paths = java.util.Collections.synchronizedList(mutableListOf<String>())

    private val now = java.time.OffsetDateTime.now().toString()

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
                    path.endsWith("/chats") -> json("""{"waiting_count":1,"data":[
                        {"id":7,"name":"Bulbul Ahmed","number":"01714080210","customer_id":"0003","last_message_at":"$now",
                         "last_message":{"type":"text","body":"লাইন বন্ধ, লাল বাতি জ্বলছে","direction":"in","sender":"customer"},
                         "waiting":true,"staff":false,"assigned":null,"bot_paused":false,"notify_muted":false},
                        {"id":8,"name":"Rahima Khatun","number":"01811000000","customer_id":"0004","last_message_at":"2026-10-18T09:00:00+06:00",
                         "last_message":{"type":"text","body":"বিল কত?","direction":"out","sender":"bot"},
                         "waiting":false,"staff":false,"assigned":{"id":1,"name":"Zisan"},"bot_paused":true,"notify_muted":false},
                        {"id":9,"name":"Rafiq","number":"01911000000","last_message_at":"2026-10-12T09:00:00+06:00",
                         "last_message":{"type":"audio","direction":"in","sender":"customer"},
                         "waiting":false,"staff":true,"assigned":null,"bot_paused":false,"notify_muted":true}]}""")
                    path.endsWith("/chats/7") -> json("""{"chat":{"id":7,"name":"Bulbul Ahmed","number":"01714080210","customer_id":"0003",
                        "waiting":true,"assigned":{"id":1,"name":"Zisan"},"bot_paused":true,"bot_paused_until":"$now","window_open":true,"sending":true},
                        "members":[{"id":1,"name":"Zisan"},{"id":2,"name":"Arif"}],
                        "messages":[
                        {"id":1,"direction":"in","sender":"customer","type":"text","body":"লাইন বন্ধ, লাল বাতি জ্বলছে","at":"$now"},
                        {"id":2,"direction":"out","sender":"bot","type":"text","body":"Bulbul Ahmed, আপনার ONU-তে লাল বাতি মানে ফাইবারে সমস্যা। টিকিট খুলে দিচ্ছি।","at":"$now"},
                        {"id":3,"direction":"in","sender":"customer","type":"audio","has_media":true,"media_mime":"audio/ogg","at":"$now"},
                        {"id":4,"direction":"out","sender":"staff","user":"Zisan","type":"text","body":"টেকনিশিয়ান আসছে।\n\n- Zisan","status":"sent","at":"$now"}]}""")
                    path.endsWith("/tickets") -> json("""{"counts":{"open":2,"pending":1,"processing":1},"next_page":null,"data":[$ticket,
                        {"id":6,"complain_id":"57232","customer_name":"Rahima Khatun","customer_id":"0004","header_id":4,
                        "category":"Slow Speed","priority":"Medium","state":"processing","person":"Rifat","zone":"Murma",
                        "opened_at":"$now","duration":"20 মিনিট","open":true}]}""")
                    path.endsWith("/tickets/5") -> json("""{"ticket":$ticket,"live":{"mikrotik":{"online":false,"router":"CLN-3"},
                        "onu":{"OpticalPower":"-29.4","OnuStatus":"Offline","OLTName":"CLN-VSOL-42","OLTPort":"PON 2",
                        "LastDeregisterTime":"2026-10-19 09:12","DeregisterReason":"LOS"},"mac":"AA:BB:CC:DD:EE:FF"},"live_error":null}""")
                    path.endsWith("/ticket-options") -> json("""{"categories":[{"id":"7","name":"Line Off"},{"id":"8","name":"Slow Speed"}],
                        "priorities":[{"id":"1","name":"Low"},{"id":"2","name":"Medium"},{"id":"3","name":"High"}],"departments":[],
                        "employees":[{"id":"3","name":"Maruf"},{"id":"4","name":"Rifat"}],"sms_open":{"ready":true,"on":true},
                        "sms_solved":{"ready":true,"on":false},"customer":$customer,"open_ticket":"57231"}""")
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
