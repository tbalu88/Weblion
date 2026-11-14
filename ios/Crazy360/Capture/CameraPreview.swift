@preconcurrency import AVFoundation
import AVKit
import SwiftUI
import UIKit

/// Élő kamerakép (kitöltve, álló), és a hardveres exponálás: hangerőgomb, Bluetooth-exponáló, Camera Control.
struct CameraPreview: UIViewRepresentable {
    let session: AVCaptureSession
    /// Ha hamis (pl. nyitott beállítások), a hangerőgomb hangerő marad.
    var enabled: Bool = true
    /// Hangerőgomb / exponáló megnyomása.
    var onTrigger: () -> Void

    func makeUIView(context: Context) -> PreviewView {
        let view = PreviewView()
        view.previewLayer.session = session
        view.previewLayer.videoGravity = .resizeAspectFill
        view.onTrigger = onTrigger
        view.installCaptureEvents()
        view.setCaptureEvents(enabled: enabled)
        return view
    }

    func updateUIView(_ view: PreviewView, context: Context) {
        view.onTrigger = onTrigger
        view.setCaptureEvents(enabled: enabled)
        view.alignOrientation()
    }
}

final class PreviewView: UIView {
    override class var layerClass: AnyClass { AVCaptureVideoPreviewLayer.self }
    // a layerClass miatt a réteg mindig AVCaptureVideoPreviewLayer
    var previewLayer: AVCaptureVideoPreviewLayer { layer as! AVCaptureVideoPreviewLayer }
    var onTrigger: (() -> Void)?
    private var captureInteraction: (any UIInteraction)?

    override func layoutSubviews() {
        super.layoutSubviews()
        alignOrientation()
    }

    /// Álló előnézet (a kapcsolat csak a bemenet hozzáadása után létezik, ezért többször is próbáljuk).
    func alignOrientation() {
        guard let connection = previewLayer.connection, connection.isVideoRotationAngleSupported(90) else { return }
        if connection.videoRotationAngle != 90 { connection.videoRotationAngle = 90 }
    }

    /// iOS 17.2-től a hangerőgombok és a Bluetooth-exponálók (amelyek hangerő-fel jelet küldenek) felvételt indítanak.
    func installCaptureEvents() {
        guard #available(iOS 17.2, *) else { return }
        let interaction = AVCaptureEventInteraction { [weak self] event in
            if event.phase == .began { self?.onTrigger?() }
        }
        interaction.isEnabled = true
        addInteraction(interaction)
        captureInteraction = interaction
    }

    func setCaptureEvents(enabled: Bool) {
        guard #available(iOS 17.2, *), let interaction = captureInteraction as? AVCaptureEventInteraction else { return }
        if interaction.isEnabled != enabled { interaction.isEnabled = enabled }
    }
}
