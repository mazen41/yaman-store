package com.yaman.scanner.network

import retrofit2.http.Body
import retrofit2.http.POST

interface ScanApi {
    @POST("api/scan/upload.php")
    suspend fun upload(@Body request: UploadRequest): ApiResponse
}
