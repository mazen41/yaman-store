package com.yaman.scanner.network

data class ScanResponse(
    val success: Boolean,
    val message: String,
    val already_scanned: Boolean? = null
)
