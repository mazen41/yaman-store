package com.yaman.scanner.network

data class UploadRequest(val scans: List<ScanPayload>)
data class ScanPayload(val localId: Long, val scannedData: String, val timestamp: Long)
data class ApiResponse(val success: Boolean, val message: String)
