import SpinKit
import SwiftUI
import UIKit

/// A fő képernyő: élő kamerakép, felül a rendezvény és az állapot, alul az exponáló.
struct CaptureScreen: View {
    @Environment(AppModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.openURL) private var openURL
    /// Lap nyitva: a hangerőgomb ilyenkor hangerő marad, nem indít felvételt.
    var triggerEnabled: Bool
    var openSettings: () -> Void
    var openLibrary: () -> Void

    var body: some View {
        ZStack {
            Theme.navy.ignoresSafeArea()
            preview
            scrims
            VStack(spacing: 12) {
                topBar
                recordingTimer
                Spacer(minLength: 0)
                messages
                effectChip
                bottomBar
            }
            .padding(.horizontal, 16)
            .padding(.bottom, 8)
            countdown
        }
        .foregroundStyle(.white)
        .animation(reduceMotion ? .easeOut(duration: 0.15) : .easeOut(duration: 0.25), value: model.phase)
    }

    // MARK: kamera

    @ViewBuilder private var preview: some View {
        if model.cameraAvailable {
            CameraPreview(session: model.capture.sessionBox.session, enabled: triggerEnabled) { model.trigger() }
                .ignoresSafeArea()
        } else {
            ZStack {
                LinearGradient(colors: [Theme.navy2, Theme.navy, Theme.orange.opacity(0.35)], startPoint: .top, endPoint: .bottom)
                    .ignoresSafeArea()
                noCameraCard
                    .opacity(model.phase == .idle ? 1 : 0)
            }
        }
    }

    private var noCameraCard: some View {
        Group {
            VStack(spacing: 14) {
                Image(systemName: model.cameraDenied ? "video.slash.fill" : "video.badge.waveform")
                    .font(.system(size: 44, weight: .semibold))
                    .foregroundStyle(Theme.orange)
                Text(model.cameraDenied ? "Nincs engedély a kamerához" : "Nincs kamera – próbafelvétel")
                    .font(.title3.bold())
                Text(model.cameraDenied
                     ? "Engedélyezd a kamerát a Crazy 360-nak a Beállítások appban."
                     : "Az exponáló mesterséges felvételt készít, így a feldolgozás, a feltöltés és a QR kipróbálható.")
                    .font(.subheadline)
                    .foregroundStyle(.white.opacity(0.75))
                    .multilineTextAlignment(.center)
                if model.cameraDenied, let url = URL(string: UIApplication.openSettingsURLString) {
                    Button("Beállítások megnyitása") { openURL(url) }
                        .buttonStyle(PrimaryButtonStyle())
                        .frame(maxWidth: 280)
                }
            }
            .padding(32)
        }
    }

    private var scrims: some View {
        VStack(spacing: 0) {
            LinearGradient(colors: [.black.opacity(0.6), .clear], startPoint: .top, endPoint: .bottom)
                .frame(height: 170)
            Spacer()
            LinearGradient(colors: [.clear, .black.opacity(0.6)], startPoint: .top, endPoint: .bottom)
                .frame(height: 240)
        }
        .ignoresSafeArea()
        .allowsHitTesting(false)
    }

    // MARK: felső sáv

    private var topBar: some View {
        HStack(alignment: .center, spacing: 10) {
            Button(action: openSettings) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(model.event?.title ?? "Nincs rendezvény kiválasztva")
                        .font(.headline)
                        .lineLimit(1)
                    Text(subtitle)
                        .font(.caption)
                        .foregroundStyle(.white.opacity(0.75))
                        .lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .accessibilityHint("Beállítások megnyitása")
            uploadBadge
            OverlayIconButton(systemImage: "gearshape.fill", label: "Beállítások", action: openSettings)
        }
        .padding(.top, 6)
    }

    private var subtitle: String {
        var parts: [String] = []
        if let e = model.event { parts.append(DayString.hungarian(e.date)) }
        if let s = model.cameraStatus {
            parts.append(s.summary)
        } else if !model.cameraAvailable {
            parts.append("kamera nélkül")
        }
        return parts.isEmpty ? "Koppints a beállításokhoz" : parts.joined(separator: " · ")
    }

    @ViewBuilder private var uploadBadge: some View {
        let pending = model.clips.pendingCount
        if model.keyProblem != nil || (!model.hasKey && pending > 0) {
            Button(action: openSettings) {
                badge(icon: "key.slash", text: "Gépkulcs", color: Theme.orange)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("A feltöltés áll: hiányzó vagy hibás gépkulcs")
        } else if pending > 0 {
            badge(icon: model.isOnline ? "arrow.up.circle.fill" : "wifi.slash", text: "\(pending)",
                  color: model.isOnline ? .white : Theme.orange)
                .accessibilityLabel(model.isOnline ? "\(pending) videó feltöltésre vár" : "Nincs net, \(pending) videó vár")
        }
    }

    private func badge(icon: String, text: String, color: Color) -> some View {
        Label(text, systemImage: icon)
            .font(.subheadline.weight(.semibold))
            .monospacedDigit()
            .padding(.horizontal, 12)
            .frame(height: 36)
            .background(.ultraThinMaterial, in: Capsule())
            .foregroundStyle(color)
    }

    // MARK: felvétel közben

    @ViewBuilder private var recordingTimer: some View {
        if case .recording(let started, let seconds) = model.phase {
            TimelineView(.periodic(from: started, by: 0.25)) { context in
                let left = max(0, seconds - context.date.timeIntervalSince(started))
                HStack(spacing: 8) {
                    Circle().fill(.white).frame(width: 8, height: 8)
                    Text(left.rounded(.up).clockText)
                        .font(.headline)
                        .monospacedDigit()
                }
                .padding(.horizontal, 14)
                .frame(height: 36)
                .background(Theme.record, in: Capsule())
            }
            .transition(.opacity)
            .accessibilityLabel("Felvétel folyamatban")
        }
    }

    @ViewBuilder private var countdown: some View {
        if case .countdown(let n) = model.phase {
            Text("\(n)")
                .font(.system(size: 180, weight: .bold, design: .rounded))
                .monospacedDigit()
                .shadow(color: .black.opacity(0.5), radius: 24)
                .id(n)
                .transition(reduceMotion ? .opacity : .asymmetric(insertion: .scale(scale: 1.6).combined(with: .opacity), removal: .opacity))
                .accessibilityLabel("Indulás \(n) másodperc múlva")
                .allowsHitTesting(false)
        }
    }

    // MARK: üzenetek

    @ViewBuilder private var messages: some View {
        if let notice = model.notice {
            HStack(alignment: .top, spacing: 10) {
                Image(systemName: "exclamationmark.circle.fill").foregroundStyle(Theme.orange)
                Text(notice).font(.subheadline).frame(maxWidth: .infinity, alignment: .leading)
                Button {
                    model.notice = nil
                } label: {
                    Image(systemName: "xmark").font(.subheadline.weight(.bold)).frame(width: 44, height: 44)
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Üzenet bezárása")
            }
            .padding(.leading, 14)
            .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .transition(.opacity)
        } else if !model.hasKey && model.phase == .idle {
            HStack(spacing: 12) {
                Text("Párosítsd a telefont a panellel, hogy a videók a galériába kerüljenek.")
                    .font(.subheadline)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button("Párosítás", action: openSettings)
                    .font(.subheadline.weight(.semibold))
                    .buttonStyle(.borderedProminent)
                    .foregroundStyle(Theme.navy2)
            }
            .padding(14)
            .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        } else if let problem = model.cameraProblem {
            Text(problem)
                .font(.subheadline)
                .padding(14)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(.ultraThinMaterial, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }

    private var effectChip: some View {
        Button(action: openSettings) {
            Text(model.settings.effectSummary)
                .font(.footnote.weight(.medium))
                .lineLimit(1)
                .padding(.horizontal, 14)
                .frame(minHeight: 32)
                .background(.ultraThinMaterial, in: Capsule())
        }
        .buttonStyle(.plain)
        .opacity(model.phase == .idle || model.phase.kind == 4 ? 1 : 0)
        .accessibilityLabel("Effektek: \(model.settings.effectSummary)")
    }

    // MARK: alsó sáv

    private var bottomBar: some View {
        HStack {
            lastClip
            Spacer()
            ShutterButton(phase: model.phase) { model.trigger() }
            Spacer()
            lensButton
        }
    }

    @ViewBuilder private var lastClip: some View {
        if let clip = model.clips.clips.first {
            Button(action: openLibrary) {
                PosterThumbnail(url: model.posterURL(clip))
                    .frame(width: 52, height: 52)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).strokeBorder(.white.opacity(0.8), lineWidth: 2))
                    .frame(width: 64, height: 64)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Videók")
        } else {
            Color.clear.frame(width: 64, height: 64)
        }
    }

    private var lensButton: some View {
        Button {
            model.settings.lens = model.settings.lens == .ultraWide ? .wide : .ultraWide
        } label: {
            Text(model.settings.lens == .ultraWide ? "0,5×" : "1×")
                .font(.subheadline.weight(.bold))
                .frame(width: 52, height: 52)
                .background(.ultraThinMaterial, in: Circle())
                .frame(width: 64, height: 64)
        }
        .buttonStyle(.plain)
        .disabled(model.phase != .idle || !model.cameraAvailable)
        .opacity(model.cameraAvailable ? 1 : 0)
        .accessibilityLabel("Objektív: \(model.settings.lens.title)")
    }
}

/// Exponáló: kör, felvétel közben lekerekített négyzet és narancs haladás-gyűrű (mint a Kamera appban).
struct ShutterButton: View {
    let phase: AppModel.Phase
    var action: () -> Void

    private var isRecording: Bool {
        if case .recording = phase { true } else { false }
    }

    var body: some View {
        Button(action: action) {
            ZStack {
                Circle().strokeBorder(.white, lineWidth: 5)
                if case .recording(let started, let seconds) = phase {
                    TimelineView(.animation(minimumInterval: 1.0 / 30)) { context in
                        let p = min(1, context.date.timeIntervalSince(started) / max(seconds, 0.1))
                        Circle()
                            .inset(by: 2.5)
                            .trim(from: 0, to: p)
                            .stroke(Theme.orange, style: StrokeStyle(lineWidth: 5, lineCap: .round))
                            .rotationEffect(.degrees(-90))
                    }
                }
                RoundedRectangle(cornerRadius: isRecording ? 8 : 34, style: .continuous)
                    .fill(Theme.record)
                    .frame(width: isRecording ? 32 : 68, height: isRecording ? 32 : 68)
            }
            .frame(width: 84, height: 84)
            .contentShape(Circle())
        }
        .buttonStyle(ShutterPressStyle())
        .disabled(phase.kind == 3)
        .animation(.spring(duration: 0.3), value: isRecording)
        .accessibilityLabel(label)
    }

    private var label: String {
        switch phase {
        case .recording: "Felvétel leállítása"
        case .countdown: "Visszaszámlálás megszakítása"
        default: "Felvétel indítása"
        }
    }
}

private struct ShutterPressStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.92 : 1)
            .animation(.easeOut(duration: 0.12), value: configuration.isPressed)
    }
}
