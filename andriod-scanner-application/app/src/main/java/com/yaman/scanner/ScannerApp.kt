package com.yaman.scanner

import android.app.Application
import androidx.room.Room
import com.yaman.scanner.data.AppDatabase
import com.yaman.scanner.network.NetworkModule

class ScannerApp : Application() {
    val db: AppDatabase by lazy {
        Room.databaseBuilder(this, AppDatabase::class.java, "scanner.db").build()
    }
    val api by lazy { NetworkModule.api }
}
