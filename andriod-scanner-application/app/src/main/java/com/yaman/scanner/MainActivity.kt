package com.yaman.scanner

import android.Manifest
import android.content.pm.PackageManager
import android.graphics.Rect
import android.os.Build
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.work.*
import com.yaman.scanner.data.ScanRecord
import com.yaman.scanner.databinding.ActivityMainBinding
import com.yaman.scanner.scan.SkuFrameAnalyzer
import com.yaman.scanner.sync.SyncWorker
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private val app by lazy { application as ScannerApp }
    private val cameraExecutor = Executors.newSingleThreadExecutor()
    @Volatile private var locked = false

    private val permissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) startCamera() else binding.txtStatus.text = "Camera permission denied"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnSync.setOnClickListener {
            WorkManager.getInstance(this).enqueue(OneTimeWorkRequestBuilder<SyncWorker>().build())
            binding.txtStatus.text = "Sync scheduled"
        }

        scheduleBackgroundSync()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) startCamera()
        else permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    private fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(this)
        cameraProviderFuture.addListener({
            val cameraProvider = cameraProviderFuture.get()
            val preview = Preview.Builder().build().also { it.surfaceProvider = binding.previewView.surfaceProvider }
            val analyzer = SkuFrameAnalyzer(onStableSku = ::onScanned, focusBoxProvider = ::focusRect)
            val analysis = ImageAnalysis.Builder().setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build().also {
                it.setAnalyzer(cameraExecutor, analyzer)
            }
            cameraProvider.unbindAll()
            cameraProvider.bindToLifecycle(this, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis)
            binding.txtStatus.text = "Scanning center area..."
        }, ContextCompat.getMainExecutor(this))
    }

    private fun focusRect(): Rect? = Rect(binding.focusBox.left, binding.focusBox.top, binding.focusBox.right, binding.focusBox.bottom)

    private fun onScanned(sku: String) {
        if (locked) return
        locked = true
        vibrate()
        binding.txtDetectedSku.text = "Detected: $sku"
        lifecycleScope.launch(Dispatchers.IO) {
            try {
                val response = app.api.processScan(scanInput = sku)
                if (!response.success) throw IllegalStateException(response.message)
                runOnUiThread { binding.txtStatus.text = response.message }
            } catch (_: Exception) {
                app.db.scanDao().insert(ScanRecord(sku = sku, timestamp = System.currentTimeMillis()))
                runOnUiThread { binding.txtStatus.text = "Offline saved: $sku" }
            } finally {
                binding.previewView.postDelayed({ locked = false }, 1200)
            }
        }
    }

    private fun vibrate() {
        val vibrator = getSystemService(VIBRATOR_SERVICE) as Vibrator
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) vibrator.vibrate(VibrationEffect.createOneShot(120, VibrationEffect.DEFAULT_AMPLITUDE))
        else @Suppress("DEPRECATION") vibrator.vibrate(120)
    }

    private fun scheduleBackgroundSync() {
        val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(this).enqueueUniquePeriodicWork("scanner-sync", ExistingPeriodicWorkPolicy.UPDATE, request)
    }
}
