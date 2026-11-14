import PhotosUI
import UIKit
import SpinKit
import SwiftUI
import UniformTypeIdentifiers

/// Beállítások: rendezvény, gépkulcs, felvétel, effekt, keret és felirat, zene, kimenet.
struct SettingsView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss

    private enum ImportKind { case frame, music }
    @State private var importKind: ImportKind = .frame
    @State private var importing = false
    @State private var photoItem: PhotosPickerItem?
    @State private var importError: String?

    var body: some View {
        NavigationStack {
            Form {
                eventSection
                keySection
                captureSection
                effectSection
                overlaySection
                musicSection
                outputSection
                Section {
                    NavigationLink {
                        LibraryView()
                    } label: {
                        LabeledContent("Videók", value: "\(model.clips.clips.count)")
                    }
                }
                advancedSection
            }
            .navigationTitle("Beállítások")
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Kész") { dismiss() }
                }
            }
            .fileImporter(isPresented: $importing,
                          allowedContentTypes: importKind == .music ? [.audio] : [.png, .image]) { result in
                handleImport(result)
            }
            .onChange(of: photoItem) { _, item in
                guard let item else { return }
                Task { await importPhoto(item) }
            }
            .alert("Nem sikerült", isPresented: Binding(get: { importError != nil }, set: { if !$0 { importError = nil } })) {
                Button("Rendben", role: .cancel) {}
            } message: {
                Text(importError ?? "")
            }
        }
    }

    // MARK: rendezvény, gépkulcs

    private var eventSection: some View {
        Section {
            NavigationLink {
                EventPickerView()
            } label: {
                VStack(alignment: .leading, spacing: 2) {
                    Text(model.event?.title ?? "Válassz rendezvényt")
                    if let e = model.event {
                        Text([DayString.hungarian(e.date), e.deal == 0 ? "nincs dealhez kötve" : nil].compactMap { $0 }.joined(separator: " · "))
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }
            }
            if let e = model.event {
                ShareLink(item: model.endpoint.stationURL(ev: e.ev)) {
                    Label("Megosztó állomás linkje (iPadre)", systemImage: "ipad")
                }
                Link(destination: model.endpoint.galleryURL(ev: e.ev)) {
                    Label("A rendezvény galériája", systemImage: "photo.on.rectangle")
                }
            }
        } header: {
            Text("Rendezvény")
        }
    }

    private var keySection: some View {
        Section {
            NavigationLink {
                KeyView()
            } label: {
                LabeledContent("Gépkulcs") {
                    Text(keyStatus.text).foregroundStyle(keyStatus.color)
                }
            }
        } header: {
            Text("Galéria")
        } footer: {
            if let problem = model.keyProblem {
                Text(problem)
            } else {
                Text("A gépkulccsal kerülnek a videók a rendezvény galériájába.")
            }
        }
    }

    private var keyStatus: (text: String, color: Color) {
        if !model.hasKey { return ("Nincs megadva", Theme.orange) }
        if model.keyProblem != nil { return ("Hibás", Theme.orange) }
        return ("Párosítva", .secondary)
    }

    // MARK: felvétel

    private var captureSection: some View {
        @Bindable var model = model
        return Section {
            Picker("Objektív", selection: $model.settings.lens) {
                ForEach(Lens.allCases) { Text($0.title).tag($0) }
            }
            Picker("Képkocka / mp", selection: $model.settings.captureFPS) {
                ForEach(SpinSettings.captureFPSChoices, id: \.self) { Text("\($0) fps").tag($0) }
            }
            Stepper(value: $model.settings.recordSeconds, in: SpinSettings.recordRange, step: 1) {
                LabeledContent("Felvétel hossza", value: model.settings.recordSeconds.secondsText)
            }
            Picker("Visszaszámlálás", selection: $model.settings.countdown) {
                ForEach(SpinSettings.countdownChoices, id: \.self) { Text($0 == 0 ? "Nincs" : "\($0) mp").tag($0) }
            }
            Toggle("Hangjelzés", isOn: $model.settings.countdownSound)
            Toggle("Stabilizálás", isOn: $model.settings.stabilization)
        } header: {
            Text("Felvétel")
        } footer: {
            Text(captureFooter)
        }
    }

    private var captureFooter: String {
        var lines: [String] = []
        if let s = model.cameraStatus {
            lines.append("Kamera most: \(s.summary).")
            if s.fps < model.settings.captureFPS {
                lines.append("Ez a kamera ezzel az objektívvel legfeljebb \(s.fps) fps-t tud.")
            }
        } else if !model.cameraAvailable {
            lines.append("Nincs kamera: az exponáló próbafelvételt készít.")
        }
        if model.settings.captureFPS >= 240 {
            lines.append("240 fps-nél sötétebb, zajosabb a kép – rendezvényen a 120 fps a biztos.")
        }
        lines.append("Indítás: az exponáló, a hangerőgomb vagy egy Bluetooth-exponáló. Felvétel közben megnyomva leáll.")
        return lines.joined(separator: " ")
    }

    // MARK: effekt

    private var effectSection: some View {
        @Bindable var model = model
        return Section {
            Picker("Sebesség", selection: $model.settings.speedStyle) {
                ForEach(SpeedStyle.allCases) { Text($0.title).tag($0) }
            }
            if model.settings.speedStyle != .normal {
                Picker("Lassítás", selection: $model.settings.slowFactor) {
                    ForEach(SpinSettings.slowChoices, id: \.self) { Text($0.factorText).tag($0) }
                }
            }
            Toggle("Oda-vissza", isOn: $model.settings.boomerang)
            Stepper(value: $model.settings.trimStart, in: SpinSettings.trimRange, step: 0.1) {
                LabeledContent("Levágás az elejéről", value: model.settings.trimStart.tenthsText)
            }
            Stepper(value: $model.settings.trimEnd, in: SpinSettings.trimRange, step: 0.1) {
                LabeledContent("Levágás a végéről", value: model.settings.trimEnd.tenthsText)
            }
            LabeledContent("Kész videó", value: "kb. \(model.settings.estimatedOutputSeconds.secondsText)")
        } header: {
            Text("Effekt")
        } footer: {
            Text(effectFooter)
        }
    }

    private var effectFooter: String {
        var lines: [String] = []
        let s = model.settings
        let fps = model.cameraStatus?.fps ?? s.captureFPS
        if s.speedStyle != .normal {
            let smooth = FramePlan.smoothestSlowdown(sourceFPS: Double(fps), outputFPS: s.outputFPS)
            if s.slowFactor < smooth - 0.001 {
                let need = Int((Double(s.outputFPS) / s.slowFactor).rounded(.up))
                lines.append("A \(s.slowFactor.factorText) lassítás akkor sima, ha a felvétel legalább \(need) fps – most \(fps) fps, ezért kicsit akadhat.")
            }
        }
        if s.estimatedOutputSeconds > 60 {
            lines.append("Hosszú videó lesz: a galéria legfeljebb 80 MB-os videót fogad.")
        }
        lines.append("A levágás a kar indulását és megállását veszi le.")
        return lines.joined(separator: " ")
    }

    // MARK: keret és felirat

    private var overlaySection: some View {
        @Bindable var model = model
        return Section {
            HStack(alignment: .center, spacing: 16) {
                OverlayPreview(settings: model.settings, frame: model.frameURL)
                    .frame(width: 90, height: 160)
                VStack(alignment: .leading, spacing: 12) {
                    Button("Keret a Fájlokból…") {
                        importKind = .frame
                        importing = true
                    }
                    PhotosPicker("Keret a Fotókból…", selection: $photoItem, matching: .images)
                    if model.settings.overlayFile != nil {
                        Button("Keret nélkül", role: .destructive) { model.settings.overlayFile = nil }
                    }
                }
                .buttonStyle(.borderless)
            }
            .padding(.vertical, 4)
            TextField("Felirat felül (pl. Anna & Péter)", text: $model.settings.captionTop)
            TextField("Felirat alul (pl. a dátum)", text: $model.settings.captionBottom)
            Picker("Felirat színe", selection: $model.settings.captionColor) {
                ForEach(CaptionColor.allCases) { Text($0.title).tag($0) }
            }
            .pickerStyle(.segmented)
        } header: {
            Text("Keret és felirat")
        } footer: {
            Text("A keret 1080×1920-as PNG, átlátszó ablakkal. A Fájlok appban a Crazy 360 / Keretek mappába is teheted.")
        }
    }

    // MARK: zene

    private var musicSection: some View {
        @Bindable var model = model
        return Section {
            if model.settings.musicFile != nil {
                LabeledContent("Zene", value: model.settings.musicTitle)
                Stepper(value: $model.settings.musicStart, in: 0...600, step: 5) {
                    LabeledContent("Kezdés", value: model.settings.musicStart.clockText)
                }
                HStack(spacing: 12) {
                    Image(systemName: "speaker.fill").foregroundStyle(.secondary)
                    Slider(value: $model.settings.musicVolume, in: 0...1)
                    Image(systemName: "speaker.wave.3.fill").foregroundStyle(.secondary)
                }
                .accessibilityElement(children: .contain)
                .accessibilityLabel("Hangerő")
                Button("Zene nélkül", role: .destructive) {
                    model.settings.musicFile = nil
                    model.settings.musicTitle = ""
                }
            }
            Button(model.settings.musicFile == nil ? "Zene választása…" : "Másik zene…") {
                importKind = .music
                importing = true
            }
        } header: {
            Text("Zene")
        } footer: {
            Text("Csak saját vagy jogtiszta zenét használj. A zene a videó hosszára ismétlődik, a végén elhalkul.")
        }
    }

    // MARK: kimenet, haladó

    private var outputSection: some View {
        @Bindable var model = model
        return Section {
            Picker("Kimeneti fps", selection: $model.settings.outputFPS) {
                ForEach(SpinSettings.outputFPSChoices, id: \.self) { Text("\($0) fps").tag($0) }
            }
            Toggle("Mentés a Fotókba is", isOn: $model.settings.saveToPhotos)
        } header: {
            Text("Kész videó")
        } footer: {
            Text("1080×1920, H.264 – minden telefonon és böngészőben lejátszható.")
        }
    }

    private var advancedSection: some View {
        @Bindable var model = model
        return Section {
            LabeledContent("Verzió", value: Self.version)
            TextField("Galéria címe", text: $model.settings.serverBase)
                .keyboardType(.URL)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
        } header: {
            Text("Haladó")
        } footer: {
            Text("Csak akkor módosítsd, ha a galéria új címre költözik.")
        }
    }

    private static var version: String {
        let info = Bundle.main.infoDictionary
        let v = info?["CFBundleShortVersionString"] as? String ?? "?"
        let b = info?["CFBundleVersion"] as? String ?? "?"
        return "\(v) (\(b))"
    }

    // MARK: fájlok

    private func handleImport(_ result: Result<URL, any Error>) {
        do {
            let url = try result.get()
            switch importKind {
            case .frame: try model.importFrame(from: url)
            case .music: try model.importMusic(from: url)
            }
        } catch {
            importError = error.localizedDescription
        }
    }

    private func importPhoto(_ item: PhotosPickerItem) async {
        defer { photoItem = nil }
        do {
            guard let data = try await item.loadTransferable(type: Data.self) else { return }
            try model.importFrame(data: data, name: "foto-keret.png")
        } catch {
            importError = error.localizedDescription
        }
    }
}

/// A keret és a felirat előnézete kicsiben (a valódi rajzolóval).
struct OverlayPreview: View {
    let settings: SpinSettings
    let frame: URL?
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            LinearGradient(colors: [Theme.navy2, Theme.orange.opacity(0.75)], startPoint: .top, endPoint: .bottom)
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFit()
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
        .task(id: key) {
            image = OverlayBuilder.make(width: 270, height: 480, frame: frame, top: settings.captionTop,
                                        bottom: settings.captionBottom, color: settings.captionColor).map { UIImage(cgImage: $0) }
        }
        .accessibilityLabel("A keret és a felirat előnézete")
    }

    private var key: String {
        "\(frame?.path ?? "")|\(settings.captionTop)|\(settings.captionBottom)|\(settings.captionColor.rawValue)"
    }
}
