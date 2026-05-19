package com.yaman.scanner.data

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "scan_records")
data class ScanRecord(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val sku: String,
    val selectedItemId: Long = 0,
    val timestamp: Long,
    val synced: Boolean = false
)
