package com.yaman.scanner

import android.Manifest
import android.content.pm.PackageManager
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.yaman.scanner.data.ScanRecord
import com.yaman.scanner.databinding.ActivityMainBinding
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

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        if (granted) startCamera() else binding.txtStatus.text = "Camera permission denied"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnSubmit.setOnClickListener {
            val value = binding.manualInput.text?.toString()?.trim().orEmpty()
            if (value.isNotEmpty()) persistScan(value)
        }

        binding.btnSync.setOnClickListener {
            WorkManager.getInstance(this).enqueue(OneTimeWorkRequestBuilder<SyncWorker>().build())
            binding.txtStatus.text = "Sync scheduled"
        }

        scheduleBackgroundSync()

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            startCamera()
        } else permissionLauncher.launch(Manifest.permission.CAMERA)
    }

    private fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(this)
        cameraProviderFuture.addListener({
            val cameraProvider = cameraProviderFuture.get()
            val preview = Preview.Builder().build().also { it.surfaceProvider = binding.previewView.surfaceProvider }

            val barcodeScanner = BarcodeScanning.getClient(
                BarcodeScannerOptions.Builder().setBarcodeFormats(
                    com.google.mlkit.vision.barcode.common.Barcode.FORMAT_ALL_FORMATS
                ).build()
            )
            val textScanner = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)

            val analysis = ImageAnalysis.Builder().build().also {
                it.setAnalyzer(cameraExecutor) { imageProxy ->
                    val mediaImage = imageProxy.image
                    if (mediaImage == null || locked) {
                        imageProxy.close(); return@setAnalyzer
                    }
                    val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
                    barcodeScanner.process(image)
                        .addOnSuccessListener { codes ->
                            val raw = codes.firstOrNull()?.rawValue
                            if (!raw.isNullOrBlank()) onScanned(raw)
                            else {
                                textScanner.process(image)
                                    .addOnSuccessListener { text ->
                                        val sku = Regex("(?i)S\\s*K\\W*(\\d{10,})").find(text.text)?.groupValues?.getOrNull(1)
                                        if (!sku.isNullOrBlank()) onScanned("SK$sku")
                                    }
                                    .addOnCompleteListener { imageProxy.close() }
                            }
                        }
                        .addOnFailureListener { imageProxy.close() }
                        .addOnCompleteListener { if (imageProxy.image != null) imageProxy.close() }
                }
            }
            cameraProvider.unbindAll()
            cameraProvider.bindToLifecycle(this, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis)
            binding.txtStatus.text = "Scanner ready"
        }, ContextCompat.getMainExecutor(this))
    }

    private fun onScanned(value: String) {
        if (locked) return
        locked = true
        runOnUiThread {
            binding.manualInput.setText(value)
            persistScan(value)
        }
        binding.previewView.postDelayed({ locked = false }, 1200)
    }

    private fun persistScan(value: String) {
        lifecycleScope.launch(Dispatchers.IO) {
            app.db.scanDao().insert(ScanRecord(scannedData = value, timestamp = System.currentTimeMillis()))
            runOnUiThread { binding.txtStatus.text = "Saved locally: $value" }
        }
    }

    private fun scheduleBackgroundSync() {
        val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        WorkManager.getInstance(this)
            .enqueueUniquePeriodicWork("scanner-sync", ExistingPeriodicWorkPolicy.UPDATE, request)
    }
}
