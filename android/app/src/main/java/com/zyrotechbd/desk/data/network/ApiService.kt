package com.zyrotechbd.desk.data.network

import okhttp3.ResponseBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {
    @POST("login")
    suspend fun login(@Body request: LoginRequest): LoginResponse

    @GET("me")
    suspend fun me(): Profile

    @POST("logout")
    suspend fun logout(): OkResponse

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
}
