package com.yaman.scanner.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.yaman.scanner.ScannerApp
import com.yaman.scanner.network.ScanPayload
import com.yaman.scanner.network.UploadRequest

class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext as ScannerApp
        val unsynced = app.db.scanDao().unsynced()
        if (unsynced.isEmpty()) return Result.success()
        return try {
            val response = app.api.upload(
                UploadRequest(
                    unsynced.map { ScanPayload(it.id, it.scannedData, it.timestamp) }
                )
            )
            if (response.success) {
                app.db.scanDao().markSynced(unsynced.map { it.id })
                Result.success()
            } else Result.retry()
        } catch (_: Exception) {
            Result.retry()
        }
    }
}
