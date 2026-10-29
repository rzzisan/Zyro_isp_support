package com.zyrotechbd.desk.data.network

import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {
    @POST("login")
    suspend fun login(@Body request: LoginRequest): LoginResponse

    @GET("me")
    suspend fun me(): Profile

    @POST("privacy")
    suspend fun acceptPrivacy(): Profile

    @POST("logout")
    suspend fun logout(): OkResponse

    @POST("password")
    suspend fun changePassword(@Body request: PasswordRequest): OkResponse

    @POST("push-token")
    suspend fun pushToken(@Body request: PushTokenRequest): OkResponse

    @GET("c/{company}/dashboard")
    suspend fun dashboard(@Path("company") company: String): Dashboard

    @GET("c/{company}/customers")
    suspend fun customers(
        @Path("company") company: String,
        @Query("q") query: String? = null,
        @Query("due") due: Int? = null,
        @Query("zone") zone: String? = null,
        @Query("page") page: Int? = null,
        @Query("subzone") subzone: String? = null,
        @Query("box") box: String? = null,
        @Query("package") pkg: String? = null,
        @Query("status") status: String? = null,
        @Query("line") line: String? = null,
        @Query("online") online: Int? = null,
        @Query("exp_from") expFrom: Int? = null,
        @Query("exp_to") expTo: Int? = null,
        @Query("list") list: String? = null,
    ): CustomerPage

    @GET("c/{company}/customer-filters")
    suspend fun customerFilters(@Path("company") company: String): CustomerFilterChoices

    @GET("c/{company}/zones")
    suspend fun zones(@Path("company") company: String): ZoneList

    @GET("c/{company}/customers/{headerId}")
    suspend fun customer(@Path("company") company: String, @Path("headerId") headerId: Long): CustomerDetail

    @POST("c/{company}/customers/{headerId}/refresh")
    suspend fun refreshCustomer(@Path("company") company: String, @Path("headerId") headerId: Long): Running

    @GET("c/{company}/customers/{headerId}/refresh")
    suspend fun customerRefreshing(@Path("company") company: String, @Path("headerId") headerId: Long): Running

    @POST("c/{company}/customers/{headerId}/line")
    suspend fun switchLine(@Path("company") company: String, @Path("headerId") headerId: Long, @Body request: LineRequest): LineResult

    @GET("c/{company}/monitoring")
    suspend fun monitoring(
        @Path("company") company: String,
        @Query("state") state: String? = null,
        @Query("server") server: String? = null,
        @Query("q") query: String? = null,
        @Query("page") page: Int? = null,
    ): MonitorPage

    @POST("c/{company}/monitoring/{headerId}/{what}")
    suspend fun monitorCheck(@Path("company") company: String, @Path("headerId") headerId: Long, @Path("what") what: String): MonitorCheck

    @POST("c/{company}/monitoring/sync")
    suspend fun monitorSync(@Path("company") company: String): SyncResult

    @GET("c/{company}/calls")
    suspend fun calls(
        @Path("company") company: String,
        @Query("who") who: String? = null,
        @Query("callback") callback: Int? = null,
        @Query("q") query: String? = null,
        @Query("page") page: Int? = null,
    ): CallPage

    @POST("c/{company}/calls/{id}/callback-done")
    suspend fun callbackDone(@Path("company") company: String, @Path("id") id: Long): CallResult

    @GET("c/{company}/call-duty")
    suspend fun callDuty(@Path("company") company: String): CallDuty

    @POST("c/{company}/call-duty")
    suspend fun setCallDuty(@Path("company") company: String, @Body request: CallDutyRequest): CallDuty

    @GET("c/{company}/softphone")
    suspend fun softphone(@Path("company") company: String): SoftphoneConfig

    @GET("c/{company}/call-info")
    suspend fun callInfo(@Path("company") company: String, @Query("number") number: String): CallInfo

    @GET("c/{company}/calls/{id}")
    suspend fun call(@Path("company") company: String, @Path("id") id: Long): CallResult

    @GET("c/{company}/customers/{headerId}/live")
    suspend fun customerLive(
        @Path("company") company: String,
        @Path("headerId") headerId: Long,
        @Query("fresh") fresh: Int? = null,
    ): LiveInfo

    @GET("c/{company}/customers/{headerId}/bill-form")
    suspend fun billForm(@Path("company") company: String, @Path("headerId") headerId: Long): BillForm

    @POST("c/{company}/customers/{headerId}/bills")
    suspend fun collectBill(
        @Path("company") company: String,
        @Path("headerId") headerId: Long,
        @Body request: BillRequest,
    ): BillResult

    @GET("c/{company}/daily-bills")
    suspend fun dailyBills(
        @Path("company") company: String,
        @Query("from") from: String?,
        @Query("to") to: String?,
        @Query("status") status: String?,
        @Query("q") query: String?,
        @Query("page") page: Int?,
    ): DailyBillPage

    @GET("c/{company}/collections")
    suspend fun collections(@Path("company") company: String): CollectionList

    @GET("c/{company}/receipts/collection/{id}")
    suspend fun collectionReceipt(@Path("company") company: String, @Path("id") id: Long, @Query("w") width: Int): ResponseBody

    @GET("c/{company}/receipts/bill/{id}")
    suspend fun dailyBillReceipt(@Path("company") company: String, @Path("id") id: Long, @Query("w") width: Int): ResponseBody

    @GET("c/{company}/chats")
    suspend fun chats(@Path("company") company: String, @Query("box") box: String?, @Query("q") query: String?): ChatList

    @GET("c/{company}/chats/{id}")
    suspend fun chat(@Path("company") company: String, @Path("id") id: Long): ChatDetail

    @POST("c/{company}/chats/{id}/reply")
    suspend fun reply(@Path("company") company: String, @Path("id") id: Long, @Body request: ReplyRequest): SentMessage

    @Multipart
    @POST("c/{company}/chats/{id}/reply")
    suspend fun replyFile(
        @Path("company") company: String,
        @Path("id") id: Long,
        @Part file: MultipartBody.Part,
        @Part("body") body: RequestBody?,
        @Part("pause_minutes") pauseMinutes: RequestBody,
    ): SentMessage

    @POST("c/{company}/chats/{id}/assign")
    suspend fun assignChat(@Path("company") company: String, @Path("id") id: Long, @Body request: AssignRequest): ChatResponse

    @POST("c/{company}/chats/{id}/bot")
    suspend fun chatBot(@Path("company") company: String, @Path("id") id: Long, @Body request: BotRequest): ChatResponse

    @POST("c/{company}/chats/{id}/mute")
    suspend fun muteChat(@Path("company") company: String, @Path("id") id: Long, @Body request: MuteRequest): ChatResponse

    /** Owner only: hides a sent message in Desk (Meta can't unsend it). */
    @DELETE("c/{company}/chats/{id}/messages/{message}")
    suspend fun deleteMessage(@Path("company") company: String, @Path("id") id: Long, @Path("message") message: Long): Unit

    @GET("c/{company}/tickets")
    suspend fun tickets(
        @Path("company") company: String,
        @Query("tab") tab: String?,
        @Query("q") query: String?,
        @Query("page") page: Int?,
    ): TicketPage

    @GET("c/{company}/tickets/{id}")
    suspend fun ticket(@Path("company") company: String, @Path("id") id: Long, @Query("fresh") fresh: Int? = null): TicketDetail

    @GET("c/{company}/ticket-customers")
    suspend fun ticketCustomers(@Path("company") company: String, @Query("q") query: String): CustomerPage

    @GET("c/{company}/ticket-options")
    suspend fun ticketOptions(@Path("company") company: String, @Query("header_id") headerId: Long? = null): TicketOptions

    @POST("c/{company}/tickets")
    suspend fun createTicket(@Path("company") company: String, @Body request: NewTicketRequest): NewTicketResult

    @POST("c/{company}/tickets/{id}/assign")
    suspend fun assignTicket(@Path("company") company: String, @Path("id") id: Long, @Body request: AssignTicketRequest): OkResponse

    @POST("c/{company}/tickets/{id}/solve")
    suspend fun solveTicket(@Path("company") company: String, @Path("id") id: Long, @Body request: SolveRequest): SolveResult

    @GET("c/{company}/my-jobs")
    suspend fun myJobs(@Path("company") company: String): MyJobsPage

    @GET("c/{company}/my-jobs/{id}")
    suspend fun myJob(@Path("company") company: String, @Path("id") id: Long, @Query("fresh") fresh: Int? = null): MyJobDetail

    @GET("c/{company}/my-jobs/{id}/line")
    suspend fun jobLine(@Path("company") company: String, @Path("id") id: Long, @Query("fresh") fresh: Int? = null): LineState

    @POST("c/{company}/my-jobs/{id}/start")
    suspend fun startJob(@Path("company") company: String, @Path("id") id: Long, @Body request: StartRequest = StartRequest()): JobResponse

    @POST("c/{company}/my-jobs/{id}/pause")
    suspend fun pauseJob(@Path("company") company: String, @Path("id") id: Long, @Body request: PauseRequest): JobResponse

    @POST("c/{company}/my-jobs/{id}/resume")
    suspend fun resumeJob(@Path("company") company: String, @Path("id") id: Long): JobResponse

    @POST("c/{company}/my-jobs/{id}/solve")
    suspend fun finishJob(@Path("company") company: String, @Path("id") id: Long, @Body request: FinishRequest): SolveResult

    @GET("c/{company}/field/location")
    suspend fun fieldLocation(@Path("company") company: String): LocationState

    @POST("c/{company}/my-jobs/{id}/later")
    suspend fun laterJob(@Path("company") company: String, @Path("id") id: Long, @Query("on") on: Int): LaterResponse

    @POST("c/{company}/field/locations")
    suspend fun fieldLocations(@Path("company") company: String, @Body request: LocationBatch): LocationState

    @GET("c/{company}/walkie")
    suspend fun walkie(@Path("company") company: String): WalkieInfo

    @GET("c/{company}/walkie/groups/{group}/clips")
    suspend fun walkieClips(@Path("company") company: String, @Path("group") group: Long, @Query("page") page: Int? = null): WalkieClipPage

    @POST("c/{company}/walkie/calls")
    suspend fun walkieCall(@Path("company") company: String, @Body request: WalkieCallStart): WalkieCallInfo

    @GET("c/{company}/walkie/calls/{id}")
    suspend fun walkieCallInfo(@Path("company") company: String, @Path("id") id: Long): WalkieCallInfo

    @POST("c/{company}/walkie/calls/{id}/answer")
    suspend fun walkieAnswer(@Path("company") company: String, @Path("id") id: Long): WalkieCallInfo

    @POST("c/{company}/walkie/calls/{id}/end")
    suspend fun walkieEnd(@Path("company") company: String, @Path("id") id: Long): WalkieCallInfo

    @GET("c/{company}/team-chat")
    suspend fun teamChats(@Path("company") company: String): TeamChatList

    /** after = only newer messages (the open chat polls with it), before = 50 older ones. */
    @GET("c/{company}/team-chat/{chat}")
    suspend fun teamChat(@Path("company") company: String, @Path("chat") chat: String,
                         @Query("after") after: Long? = null, @Query("before") before: Long? = null): TeamChatDetail

    @POST("c/{company}/team-chat/{chat}")
    suspend fun teamSend(@Path("company") company: String, @Path("chat") chat: String, @Body request: TeamTextRequest): TeamSent

    /** A photo (kind image, optional caption) or a voice message (kind voice, seconds). */
    @Multipart
    @POST("c/{company}/team-chat/{chat}")
    suspend fun teamSendFile(
        @Path("company") company: String,
        @Path("chat") chat: String,
        @Part file: MultipartBody.Part,
        @Part("kind") kind: RequestBody,
        @Part("body") body: RequestBody?,
        @Part("seconds") seconds: RequestBody?,
    ): TeamSent

    @POST("c/{company}/team-chat/{chat}/mute")
    suspend fun teamMute(@Path("company") company: String, @Path("chat") chat: String, @Body request: MuteRequest): TeamMuted

    @DELETE("c/{company}/team-chat/{chat}/messages/{message}")
    suspend fun teamDelete(@Path("company") company: String, @Path("chat") chat: String, @Path("message") message: Long): Unit
}
