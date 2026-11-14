import AVFoundation
import SpinKit
import SwiftUI
import UIKit

/// A kész videó: ismétlődő lejátszás, QR a galéria-linkkel, feltöltési állapot, új felvétel.
struct ResultView: View {
    @Environment(AppModel.self) private var model
    let clipID: String
    @State private var muted = false

    var body: some View {
        ZStack {
            Theme.navy.ignoresSafeArea()
            if let clip = model.clips[clipID] {
                VStack(spacing: 14) {
                    HStack(spacing: 10) {
                        OverlayIconButton(systemImage: "xmark", label: "Vissza a kamerához") { model.closeResult() }
                        Spacer()
                        OverlayIconButton(systemImage: muted ? "speaker.slash.fill" : "speaker.wave.2.fill",
                                          label: muted ? "Hang be" : "Hang ki") { muted.toggle() }
                        ShareLink(item: model.videoURL(clip)) {
                            Image(systemName: "square.and.arrow.up")
                                .font(.title3.weight(.semibold))
                                .frame(width: 52, height: 52)
                                .background(.ultraThinMaterial, in: Circle())
                        }
                        .accessibilityLabel("Videó megosztása")
                    }
                    LoopingPlayer(url: model.videoURL(clip), muted: muted)
                        .aspectRatio(9.0 / 16.0, contentMode: .fit)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .accessibilityLabel("A kész videó")
                    QRCard(clip: clip)
                    Button("Új felvétel") { model.trigger() }
                        .buttonStyle(PrimaryButtonStyle())
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 8)
            }
        }
        .foregroundStyle(.white)
    }
}

/// QR + link + feltöltési állapot.
struct QRCard: View {
    @Environment(AppModel.self) private var model
    let clip: Clip

    var body: some View {
        let link = model.shareURL(clip).absoluteString
        HStack(spacing: 14) {
            QRImage(text: link)
                .frame(width: 112, height: 112)
                .padding(8)
                .background(.white, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            VStack(alignment: .leading, spacing: 6) {
                Text("Szkenneld be a videóért")
                    .font(.headline)
                UploadStatusLabel(clip: clip)
                    .font(.subheadline)
                Text(link)
                    .font(.caption)
                    .foregroundStyle(.white.opacity(0.6))
                    .lineLimit(1)
                    .truncationMode(.middle)
            }
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(Theme.navy2, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

struct QRImage: View {
    let text: String
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            if let image {
                Image(uiImage: image)
                    .interpolation(.none)
                    .resizable()
                    .scaledToFit()
            } else {
                Color.white
            }
        }
        .task(id: text) { image = QRCode.image(for: text) }
        .accessibilityLabel("QR-kód a videó letöltéséhez")
    }
}

/// A feltöltés állapota szövegesen, ikonnal.
struct UploadStatusLabel: View {
    @Environment(AppModel.self) private var model
    let clip: Clip

    var body: some View {
        let s = status
        Label(s.text, systemImage: s.icon)
            .foregroundStyle(s.color)
    }

    private var status: (text: String, icon: String, color: Color) {
        switch clip.upload {
        case .done:
            return ("Fent van a galériában", "checkmark.circle.fill", Theme.ok)
        case .uploading:
            return ("Feltöltés…", "arrow.up.circle", .white)
        case .stopped(let message):
            return (message, "exclamationmark.triangle.fill", Theme.orange)
        case .waiting:
            if !model.hasKey { return ("Vár – nincs gépkulcs", "key", Theme.orange) }
            if model.keyProblem != nil { return ("Vár – hibás gépkulcs", "key.slash", Theme.orange) }
            if !model.isOnline { return ("Nincs net – magától feltöltődik", "wifi.slash", .white.opacity(0.75)) }
            if clip.lastError != nil { return ("Hamarosan újrapróbáljuk", "arrow.clockwise", .white.opacity(0.75)) }
            return ("Feltöltésre vár", "clock", .white.opacity(0.75))
        }
    }
}

/// Ismétlődő lejátszó (AVPlayerLooper), kitöltés nélkül.
struct LoopingPlayer: UIViewRepresentable {
    let url: URL
    var muted: Bool

    func makeUIView(context: Context) -> PlayerView {
        let view = PlayerView()
        view.play(url)
        view.player.isMuted = muted
        return view
    }

    func updateUIView(_ view: PlayerView, context: Context) {
        view.play(url)
        view.player.isMuted = muted
    }

    static func dismantleUIView(_ view: PlayerView, coordinator: ()) {
        view.player.pause()
    }
}

final class PlayerView: UIView {
    override class var layerClass: AnyClass { AVPlayerLayer.self }
    // a layerClass miatt a réteg mindig AVPlayerLayer
    private var playerLayer: AVPlayerLayer { layer as! AVPlayerLayer }
    let player = AVQueuePlayer()
    private var looper: AVPlayerLooper?
    private var current: URL?

    override init(frame: CGRect) {
        super.init(frame: frame)
        playerLayer.player = player
        playerLayer.videoGravity = .resizeAspect
        backgroundColor = .black
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { nil }

    func play(_ url: URL) {
        guard url != current else { return }
        current = url
        player.removeAllItems()
        looper = AVPlayerLooper(player: player, templateItem: AVPlayerItem(url: url))
        player.play()
    }
}
