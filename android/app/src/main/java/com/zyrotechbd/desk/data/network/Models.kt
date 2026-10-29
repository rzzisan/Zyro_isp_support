package com.zyrotechbd.desk.data.network

import com.squareup.moshi.Json

data class ApiError(val message: String? = null, val errors: Map<String, List<String>>? = null)

data class OkResponse(val ok: Boolean = false)

// ── Auth ───────────────────────────────────────────────────────────────────────

data class PasswordRequest(
    @Json(name = "current_password") val currentPassword: String,
    val password: String,
    @Json(name = "password_confirmation") val passwordConfirmation: String,
)

data class LoginRequest(val email: String, val password: String, val device: String, val privacy: Boolean = false)

data class UserInfo(
    val id: Long,
    val name: String,
    val email: String,
    val locale: String? = null,
    @Json(name = "privacy_accepted") val privacyAccepted: Boolean = true,
)

/** A company the user works in and the Desk menus they may open there (inbox, customers, bills, ...). */
data class CompanyInfo(
    val id: Long,
    val slug: String,
    val name: String,
    val role: String? = null,
    val menus: List<String> = emptyList(),
) {
    fun can(menu: String) = menu in menus

    /** A field technician: chats only with customers whose work they have started (the server enforces it too). */
    val fieldOnly get() = role == "agent" && can("field_jobs")
}

data class LoginResponse(val token: String, val user: UserInfo, val companies: List<CompanyInfo>)

data class Profile(val user: UserInfo, val companies: List<CompanyInfo>)

// ── Dashboard ──────────────────────────────────────────────────────────────────

data class Dashboard(
    val bills: DashBills? = null,
    val tickets: DashTickets? = null,
    val network: DashNetwork? = null,
    val inbox: DashInbox? = null,
)

data class DashBills(
    val count: Int = 0,
    val paid: Double = 0.0,
    val pending: Double = 0.0,
    @Json(name = "mine_count") val mineCount: Int = 0,
    @Json(name = "mine_paid") val minePaid: Double = 0.0,
)

data class DashTickets(
    val open: Int = 0,
    @Json(name = "opened_today") val openedToday: Int = 0,
    @Json(name = "solved_today") val solvedToday: Int = 0,
)

data class DashNetwork(val online: Int = 0, @Json(name = "active_customers") val activeCustomers: Int = 0)

data class DashInbox(val waiting: Int = 0)

// ── Customers ──────────────────────────────────────────────────────────────────

data class Customer(
    @Json(name = "header_id") val headerId: Long,
    @Json(name = "customer_id") val customerId: String? = null,
    val name: String? = null,
    val mobile: String? = null,
    val username: String? = null,
    val zone: String? = null,
    val subzone: String? = null,
    val box: String? = null,
    val `package`: String? = null,
    @Json(name = "monthly_bill") val monthlyBill: Double? = null,
    val due: Double? = null,
    val status: String? = null,
    val disabled: Boolean? = null,
    @Json(name = "is_left") val isLeft: Boolean? = null,
    @Json(name = "left_on") val leftOn: String? = null,
    @Json(name = "last_payment_date") val lastPaymentDate: String? = null,
    @Json(name = "bill_day") val billDay: Int? = null,
    val online: Boolean = false,
    val address: String? = null,
    @Json(name = "joined_on") val joinedOn: String? = null,
    val server: String? = null,
    @Json(name = "chat_id") val chatId: Long? = null,
) {
    fun place(): String = listOfNotNull(zone, subzone, box).map { it.trim() }.filter { it.isNotEmpty() }.joinToString(" / ")
}

data class CustomerPage(val data: List<Customer> = emptyList(), @Json(name = "next_page") val nextPage: Int? = null,
    val total: Int? = null)

data class Place(val zone: String = "", val subzone: String = "", val box: String = "")

/** Choices for the customer list's filters. */
data class CustomerFilterChoices(val places: List<Place> = emptyList(), val packages: List<String> = emptyList(),
    val statuses: List<String> = emptyList())

data class ZoneList(val data: List<String> = emptyList())

data class Payment(
    val date: String? = null,
    @Json(name = "bill_month") val billMonth: String? = null,
    val paid: Double = 0.0,
    val discount: Double = 0.0,
    val due: Double = 0.0,
    val method: String? = null,
    @Json(name = "received_by") val receivedBy: String? = null,
    @Json(name = "receipt_no") val receiptNo: String? = null,
)

data class Ticket(
    val id: Long? = null,
    @Json(name = "complain_id") val complainId: String? = null,
    val category: String? = null,
    val state: String? = null,
    @Json(name = "opened_at") val openedAt: String? = null,
    @Json(name = "assigned_to") val assignedTo: String? = null,
    val note: String? = null,
    @Json(name = "solved_at") val solvedAt: String? = null,
    @Json(name = "solved_by") val solvedBy: String? = null,
)

data class Permissions(val bill: Boolean = false, val line: Boolean = false)

/** One card of billing's customer details (service, network or personal tab). */
data class ProfileSection(val tab: String, val title: String, val rows: List<ProfileRow> = emptyList())

data class ProfileRow(val label: String, val value: String? = null, val map: Boolean = false)

data class LineLog(val at: String? = null, val action: String? = null, val remarks: String? = null, val by: String? = null)

data class CustomerDetail(
    val customer: Customer,
    val payments: List<Payment> = emptyList(),
    val tickets: List<Ticket> = emptyList(),
    val can: Permissions = Permissions(),
    val sections: List<ProfileSection> = emptyList(),
    @Json(name = "details_fetched_at") val detailsFetchedAt: String? = null,
    val history: List<Ticket> = emptyList(),
    @Json(name = "line_logs") val lineLogs: List<LineLog> = emptyList(),
)

data class LineRequest(val on: Boolean)

data class LineResult(val customer: Customer)

data class Running(val running: Boolean = false)

/** One line on the monitoring list: the customer plus their PPPoE session now. */
data class MonitorRow(
    @Json(name = "header_id") val headerId: Long,
    @Json(name = "customer_id") val customerId: String? = null,
    val name: String? = null,
    val mobile: String? = null,
    val username: String? = null,
    val zone: String? = null,
    val subzone: String? = null,
    val box: String? = null,
    val `package`: String? = null,
    val server: String? = null,
    val state: String = "offline",      // online | offline | blocked
    val ip: String? = null,
    val mac: String? = null,
    val uptime: String? = null,
    val profile: String? = null,
    @Json(name = "seen_at") val seenAt: String? = null,
) {
    fun place(): String = listOfNotNull(zone, subzone, box).map { it.trim() }.filter { it.isNotEmpty() }.joinToString(" / ")
}

data class ServerCount(val server: String = "", val total: Int = 0, val online: Int = 0, val blocked: Int = 0)

data class MonitorPage(
    val data: List<MonitorRow> = emptyList(),
    @Json(name = "next_page") val nextPage: Int? = null,
    val servers: List<ServerCount>? = null,
    @Json(name = "updated_at") val updatedAt: String? = null,
)

/** A router check (recheck, traffic or ping): free-form fields from the engine. */
data class MonitorCheck(val result: Map<String, Any?> = emptyMap())

data class RouterSync(val ok: Boolean = false, val router: String? = null, val online: Int? = null)

data class SyncResult(val routers: List<RouterSync> = emptyList())

/** The live box: MikroTik now, ONU now (both free-form objects from the engine), last seen MAC. */
data class LiveInfo(
    val mikrotik: Map<String, Any?>? = null,
    val onu: Map<String, Any?>? = null,
    val mac: String? = null,
    @Json(name = "last_seen") val lastSeen: Map<String, Any?>? = null,
    @Json(name = "payment_status") val paymentStatus: String? = null,
)

// ── Bills ──────────────────────────────────────────────────────────────────────

data class Choice(val id: String, val name: String)

data class BlockedLine(val since: String? = null, val by: String? = null, val profile: String? = null, val remarks: String? = null)

/** A collection of this customer still waiting for approval in billing (its due drops only after that). */
data class PendingBill(
    val id: Any? = null,          // billing's own JSON: numbers or strings
    val date: Any? = null,
    val amount: Any? = null,
    val discount: Any? = null,
    val method: String? = null,
    @Json(name = "received_by") val receivedBy: String? = null,
    @Json(name = "created_by") val createdBy: String? = null,
)

data class BillForm(
    val username: String? = null,
    val status: String? = null,
    val monthly: Double? = null,
    val payable: Double = 0.0,
    @Json(name = "bill_month") val billMonth: String? = null,
    @Json(name = "received_from") val receivedFrom: String? = null,
    val methods: List<Choice> = emptyList(),
    @Json(name = "method_default") val methodDefault: String? = null,
    @Json(name = "received_by") val receivedBy: List<Choice> = emptyList(),
    @Json(name = "received_by_default") val receivedByDefault: String? = null,
    val pending: List<PendingBill> = emptyList(),
    val blocked: BlockedLine? = null,
    @Json(name = "sms_ready") val smsReady: Boolean = false,
    @Json(name = "sms_default") val smsDefault: Boolean = false,
)

data class BillRequest(
    val paid: Double,
    val discount: Double,
    @Json(name = "method_id") val methodId: String,
    @Json(name = "received_by_id") val receivedById: String,
    @Json(name = "trx_no") val trxNo: String,
    @Json(name = "bill_month") val billMonth: String?,
    val remarks: String,
    @Json(name = "expected_payable") val expectedPayable: Double,
    @Json(name = "next_billing_date") val nextBillingDate: Boolean,
    @Json(name = "desk_sms") val deskSms: Boolean,
)

data class BillResult(
    val message: String? = null,
    val balance: Double? = null,
    @Json(name = "collection_id") val collectionId: Long? = null,
    @Json(name = "daily_bill_id") val dailyBillId: Long? = null,
    val sms: String? = null,
)

data class DailyBill(
    @Json(name = "daily_bill_id") val dailyBillId: Long,
    @Json(name = "customer_header_id") val customerHeaderId: Long? = null,
    @Json(name = "customer_id") val customerId: String? = null,
    @Json(name = "customer_name") val customerName: String? = null,
    val username: String? = null,
    val mobile: String? = null,
    @Json(name = "collected_at") val collectedAt: String? = null,
    val paid: Double = 0.0,
    val discount: Double = 0.0,
    val due: Double = 0.0,
    val method: String? = null,
    @Json(name = "received_by") val receivedBy: String? = null,
    @Json(name = "created_by") val createdBy: String? = null,
    val status: String = "pending",
    @Json(name = "receipt_no") val receiptNo: String? = null,
)

data class Totals(val count: Int = 0, val paid: Double = 0.0, val discount: Double = 0.0, val due: Double = 0.0)

data class DailyBillPage(
    val totals: Totals = Totals(),
    val data: List<DailyBill> = emptyList(),
    @Json(name = "next_page") val nextPage: Int? = null,
)

data class DeskCollection(
    val id: Long,
    @Json(name = "customer_header_id") val customerHeaderId: Long? = null,
    @Json(name = "customer_id") val customerId: String? = null,
    @Json(name = "customer_name") val customerName: String? = null,
    val username: String? = null,
    val paid: Double = 0.0,
    val discount: Double = 0.0,
    val method: String? = null,
    @Json(name = "created_at") val createdAt: String? = null,
    @Json(name = "receipt_no") val receiptNo: String? = null,
)

data class CollectionList(val data: List<DeskCollection> = emptyList())

// ── Inbox ──────────────────────────────────────────────────────────────────────

data class LastMessage(val type: String? = null, val body: String? = null, val direction: String? = null, val sender: String? = null)

data class Member(val id: Long, val name: String)

/** One WhatsApp chat in the list; [waiting] = the customer spoke last and nobody has answered. */
data class Chat(
    val id: Long,
    val name: String? = null,
    val number: String = "",
    @Json(name = "customer_id") val customerId: String? = null,
    @Json(name = "last_message_at") val lastMessageAt: String? = null,
    @Json(name = "last_message") val lastMessage: LastMessage? = null,
    val waiting: Boolean = false,
    val staff: Boolean = false,
    val assigned: Member? = null,
    @Json(name = "bot_paused") val botPaused: Boolean = false,
    @Json(name = "bot_paused_until") val botPausedUntil: String? = null,
    @Json(name = "notify_muted") val notifyMuted: Boolean = false,
    @Json(name = "window_open") val windowOpen: Boolean = true,
    @Json(name = "header_id") val headerId: Long? = null,           // the billing line this chat is about (details only)
    @Json(name = "line_customer_id") val lineCustomerId: String? = null,
    val sending: Boolean = true,
    @Json(name = "can_delete") val canDelete: Boolean = false,      // owner: may delete sent messages (details only)
)

data class ChatList(val data: List<Chat> = emptyList(), @Json(name = "waiting_count") val waitingCount: Int = 0)

data class ChatMessage(
    val id: Long,
    val direction: String = "in",
    val sender: String? = null,
    val user: String? = null,
    val type: String? = null,
    val body: String? = null,
    @Json(name = "has_media") val hasMedia: Boolean = false,
    @Json(name = "media_mime") val mediaMime: String? = null,
    val status: String? = null,
    val at: String? = null,
)

data class ChatDetail(val chat: Chat, val messages: List<ChatMessage> = emptyList(), val members: List<Member> = emptyList())

data class ChatResponse(val chat: Chat)

data class SentMessage(val message: ChatMessage)

data class ReplyRequest(val body: String, @Json(name = "pause_minutes") val pauseMinutes: Int)

data class AssignRequest(@Json(name = "user_id") val userId: Any?)

data class BotRequest(val paused: Boolean)

data class MuteRequest(val muted: Boolean)

// ── Tickets ────────────────────────────────────────────────────────────────────

/** A billing ticket, the Desk's copy (pending / processing = open; solved / closed). */
data class TicketRow(
    val id: Long,
    @Json(name = "complain_id") val complainId: String = "",
    @Json(name = "customer_name") val customerName: String? = null,
    @Json(name = "customer_id") val customerId: String? = null,
    @Json(name = "header_id") val headerId: Long? = null,
    val username: String? = null,
    val mobile: String? = null,
    @Json(name = "complain_number") val complainNumber: String? = null,
    val zone: String? = null,
    val subzone: String? = null,
    val category: String? = null,
    val priority: String? = null,
    val state: String = "pending",
    val person: String? = null,
    val description: String? = null,
    @Json(name = "created_by") val createdBy: String? = null,
    @Json(name = "opened_at") val openedAt: String? = null,
    @Json(name = "solved_at") val solvedAt: String? = null,
    val duration: String? = null,
    val open: Boolean = true,
)

data class TicketCounts(val open: Int = 0, val pending: Int = 0, val processing: Int = 0)

data class TicketPage(
    val data: List<TicketRow> = emptyList(),
    @Json(name = "next_page") val nextPage: Int? = null,
    val counts: TicketCounts = TicketCounts(),
    @Json(name = "synced_at") val syncedAt: String? = null,
)

data class TicketDetail(val ticket: TicketRow, val live: LiveInfo? = null, @Json(name = "live_error") val liveError: String? = null)

data class SmsSwitch(val ready: Boolean = false, val on: Boolean = false)

data class TicketOptions(
    val categories: List<Choice> = emptyList(),
    val priorities: List<Choice> = emptyList(),
    val departments: List<Choice> = emptyList(),
    val employees: List<Choice> = emptyList(),
    @Json(name = "sms_open") val smsOpen: SmsSwitch = SmsSwitch(),
    @Json(name = "sms_solved") val smsSolved: SmsSwitch = SmsSwitch(),
    val customer: Customer? = null,
    @Json(name = "open_ticket") val openTicket: String? = null,
)

data class NewTicketRequest(
    @Json(name = "header_id") val headerId: Long,
    @Json(name = "category_id") val categoryId: String,
    val priority: Int,
    val mobile: String,
    val comment: String,
    @Json(name = "dept_id") val deptId: String?,
    val employees: List<String>,
    @Json(name = "sms_client") val smsClient: Boolean,
)

data class NewTicketResult(@Json(name = "complain_id") val complainId: String? = null, val message: String? = null, val sms: String? = null)

data class AssignTicketRequest(val employees: List<String>, @Json(name = "dept_id") val deptId: String?)

data class SolveRequest(val remark: String?, val sms: Boolean)

data class SolveResult(val ok: Boolean = false, val sms: String? = null)

// ── Field work (আমার কাজ) ──────────────────────────────────────────────────────

/** The work on one ticket: running / paused / done, seconds worked (less pauses) when the server answered. */
data class FieldJob(
    val id: Long,
    val status: String = "running",
    @Json(name = "started_at") val startedAt: String? = null,
    @Json(name = "started_by") val startedBy: String? = null,
    @Json(name = "pause_reason") val pauseReason: String? = null,
    @Json(name = "worked_seconds") val workedSeconds: Long = 0,
    @Json(name = "target_minutes") val targetMinutes: Int = 90,
    @Json(name = "line_check") val lineCheck: Boolean = true,
    @Json(name = "line_online") val lineOnline: Boolean? = null,
    @Json(name = "finished_at") val finishedAt: String? = null,
    @Json(name = "force_reason") val forceReason: String? = null,
)

/** A ticket of mine with its job, and whether the customer is online now (our MikroTik copy). */
data class MyJobRow(
    val id: Long,
    @Json(name = "complain_id") val complainId: String = "",
    @Json(name = "customer_name") val customerName: String? = null,
    @Json(name = "customer_id") val customerId: String? = null,
    @Json(name = "header_id") val headerId: Long? = null,
    val mobile: String? = null,
    /** The contact number given when the ticket was opened (may be another than the customer's own). */
    @Json(name = "complain_number") val complainNumber: String? = null,
    val zone: String? = null,
    val subzone: String? = null,
    val category: String? = null,
    val priority: String? = null,
    val state: String = "pending",
    val person: String? = null,
    val description: String? = null,
    @Json(name = "opened_at") val openedAt: String? = null,
    @Json(name = "solved_at") val solvedAt: String? = null,
    val duration: String? = null,
    val open: Boolean = true,
    val job: FieldJob? = null,
    /** Put off ("আগামীকাল করব") to this day (yyyy-MM-dd); null when it is today's work. */
    @Json(name = "later_on") val laterOn: String? = null,
    val online: Boolean? = null,
)

data class MyJobsPage(val data: List<MyJobRow> = emptyList(), val linked: Boolean = true)

data class MyJobDetail(
    val ticket: MyJobRow,
    val customer: Customer? = null,
    val live: LiveInfo? = null,
    @Json(name = "live_error") val liveError: String? = null,
    @Json(name = "target_minutes") val targetMinutes: Int = 90,
    @Json(name = "line_check") val lineCheck: Boolean = true,
    @Json(name = "can_force") val canForce: Boolean = false,
    @Json(name = "sms_solved") val smsSolved: SmsSwitch = SmsSwitch(),
)

data class JobResponse(val job: FieldJob)

data class LaterResponse(val ticket: MyJobRow)

data class PauseRequest(val reason: String)

data class LineState(
    val online: Boolean? = null,
    val blocked: Boolean = false,
    val uptime: String? = null,
    val address: String? = null,
    val error: String? = null,
    @Json(name = "checked_at") val checkedAt: String? = null,
)

data class FinishRequest(
    val remark: String?,
    val sms: Boolean,
    @Json(name = "force_reason") val forceReason: String?,
    val lat: Double? = null,
    val lng: Double? = null,
    val accuracy: Float? = null,
    val mock: Boolean? = null,
)

/** Where the phone was when the work was started (null fields when no location). */
data class StartRequest(val lat: Double? = null, val lng: Double? = null, val accuracy: Float? = null, val mock: Boolean? = null)

// ── Field location (told to the technician; owner-only map) ─────────────────────

data class LocationState(
    val consent: Boolean = false,
    @Json(name = "on_duty") val onDuty: Boolean = false,
    @Json(name = "on_duty_since") val onDutySince: String? = null,
    @Json(name = "job_running") val jobRunning: Boolean = false,
    val track: Boolean = false,
    val interval: Int = 300,
    val saved: Int? = null,
)

data class LocationPoint(
    val lat: Double,
    val lng: Double,
    val accuracy: Float? = null,
    val mock: Boolean = false,
    val battery: Int? = null,
    val at: String,
)

data class LocationBatch(val points: List<LocationPoint>)

// ── Push ───────────────────────────────────────────────────────────────────────

data class PushTokenRequest(val token: String)

data class CallCustomer(
    @Json(name = "header_id") val headerId: Long,
    @Json(name = "customer_id") val customerId: String? = null,
    val name: String? = null,
    val left: Boolean = false,
)

/** One hotline call (the panel's "কল লগ"); a bot-answered one may have a recording and a transcript. */
data class CallRow(
    val id: Long,
    val caller: String? = null,
    @Json(name = "started_at") val startedAt: String? = null,
    @Json(name = "ended_at") val endedAt: String? = null,
    val ringing: Boolean = false,
    @Json(name = "bot_answered") val botAnswered: Boolean = false,
    @Json(name = "bot_seconds") val botSeconds: Int? = null,
    val flag: String? = null,
    @Json(name = "callback_reason") val callbackReason: String? = null,
    @Json(name = "has_recording") val hasRecording: Boolean = false,
    val customer: CallCustomer? = null,
    val transcript: List<CallLine>? = null,
    @Json(name = "bot_error") val botError: String? = null,
)

data class CallLine(val who: String = "", val text: String = "", val at: Int = 0)

data class CallPage(val data: List<CallRow> = emptyList(), @Json(name = "next_page") val nextPage: Int? = null)

data class CallResult(val call: CallRow)

/** The call screen: the caller's lines (customers with this mobile, or named in their WhatsApp chat), open tickets each. */
data class CallInfo(val lines: List<CallerLine> = emptyList(), val can: CallInfoCan = CallInfoCan())

data class CallerLine(val customer: Customer, val tickets: List<Ticket> = emptyList())

data class CallInfoCan(val ticket: Boolean = false, val customer: Boolean = false)

/** The member's Desk phone login (call/CallActivity hands it to the softphone page). */
data class SoftphoneConfig(
    val ws: String,
    val domain: String,
    val user: String,
    val password: String,
    val name: String = "",
    @Json(name = "can_call_out") val canCallOut: Boolean = false,
)

// ── Walkie-talkie ─────────────────────────────────────────────────────────────

/** The member's walkie-talkie groups, each with a token for its LiveKit room, and the daily on/off times (Asia/Dhaka). */
data class WalkieInfo(
    val url: String? = null,
    val me: Long = 0,
    @Json(name = "on_at") val onAt: String = "10:00",
    @Json(name = "off_at") val offAt: String? = null,
    val groups: List<WalkieGroup> = emptyList(),
)

data class WalkieGroup(val id: Long, val name: String, val room: String, val token: String, val members: List<WalkieMember> = emptyList())

data class WalkieMember(val id: Long, val name: String)

data class WalkieClip(
    val id: Long,
    val user: String? = null,
    @Json(name = "user_id") val userId: Long? = null,
    @Json(name = "started_at") val startedAt: String,
    val seconds: Double = 0.0,
)

data class WalkieClipPage(val data: List<WalkieClip> = emptyList(), @Json(name = "next_page") val nextPage: Int? = null)

data class WalkieCallStart(@Json(name = "user_id") val userId: Long)

/** A private call: its room and a token while it is ringing or talking. */
data class WalkieCallInfo(
    val id: Long,
    val status: String,
    val room: String,
    val outgoing: Boolean = false,
    val other: WalkieMember? = null,
    val url: String? = null,
    val token: String? = null,
)
