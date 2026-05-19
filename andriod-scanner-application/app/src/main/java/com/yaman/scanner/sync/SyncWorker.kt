package com.yaman.scanner.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.yaman.scanner.ScannerApp

class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext as ScannerApp
        val unsynced = app.db.scanDao().unsynced()
        if (unsynced.isEmpty()) return Result.success()

        unsynced.forEach { record ->
            try {
                val response = app.api.processScan(scanInput = record.sku, selectedItemId = record.selectedItemId)
                if (response.success) app.db.scanDao().markSynced(record.id) else return Result.retry()
            } catch (_: Exception) {
                return Result.retry()
            }
        }
        return Result.success()
    }
}
