package com.yaman.scanner.network

import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.POST

interface ScanApi {
    @FormUrlEncoded
    @POST("modules/sorting/ajax_scan.php")
    suspend fun processScan(
        @Field("action") action: String = "scan",
        @Field("scan_input") scanInput: String,
        @Field("selected_item_id") selectedItemId: Long = 0
    ): ScanResponse
}
