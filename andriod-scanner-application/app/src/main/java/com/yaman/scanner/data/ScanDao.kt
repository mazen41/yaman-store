package com.yaman.scanner.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query

@Dao
interface ScanDao {
    @Insert
    suspend fun insert(record: ScanRecord)

    @Query("SELECT * FROM scan_records WHERE synced = 0 ORDER BY timestamp ASC")
    suspend fun unsynced(): List<ScanRecord>

    @Query("UPDATE scan_records SET synced = 1 WHERE id IN (:ids)")
    suspend fun markSynced(ids: List<Long>)
}
