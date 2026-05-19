package com.yaman.scanner.scan

import android.graphics.Rect
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import java.util.ArrayDeque
import java.util.concurrent.atomic.AtomicBoolean

class SkuFrameAnalyzer(
    private val onStableSku: (String) -> Unit,
    private val focusBoxProvider: () -> Rect?
) : ImageAnalysis.Analyzer {
    private val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
    private val processing = AtomicBoolean(false)
    private val skuRegex = Regex("SK\\d+", RegexOption.IGNORE_CASE)
    private val history = ArrayDeque<String>()
    private val stabilityFrames = 3

    override fun analyze(imageProxy: ImageProxy) {
        if (!processing.compareAndSet(false, true)) { imageProxy.close(); return }
        val mediaImage = imageProxy.image ?: run { processing.set(false); imageProxy.close(); return }
        val image = InputImage.fromMediaImage(mediaImage, imageProxy.imageInfo.rotationDegrees)
        recognizer.process(image)
            .addOnSuccessListener { text ->
                val focus = focusBoxProvider()
                val candidates = text.textBlocks.flatMap { block ->
                    val box = block.boundingBox
                    val allowed = focus == null || (box != null && Rect.intersects(focus, box))
                    if (!allowed) emptyList() else skuRegex.findAll(block.text.replace(" ", "")).map { it.value.uppercase() }.toList()
                }
                val sku = candidates.firstOrNull()
                if (sku != null) {
                    history.addLast(sku)
                    while (history.size > stabilityFrames) history.removeFirst()
                    if (history.size == stabilityFrames && history.all { it == sku }) {
                        history.clear(); onStableSku(sku)
                    }
                } else history.clear()
            }
            .addOnCompleteListener { processing.set(false); imageProxy.close() }
    }
}
