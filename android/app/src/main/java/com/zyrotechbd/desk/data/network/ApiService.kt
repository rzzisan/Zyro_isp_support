package com.zyrotechbd.desk.data.network

import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.http.Body
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

    @POST("logout")
    suspend fun logout(): OkResponse

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
    ): CustomerPage

    @GET("c/{company}/zones")
    suspend fun zones(@Path("company") company: String): ZoneList

    @GET("c/{company}/customers/{headerId}")
    suspend fun customer(@Path("company") company: String, @Path("headerId") headerId: Long): CustomerDetail

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
}
