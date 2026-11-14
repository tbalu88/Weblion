import AVFoundation

/// A zene a kész videó alá: a megadott ponttól indul, ha rövidebb, ismétlődik; elején rövid be-, végén kiúsztatás.
/// A hangerő-ívet az AVAudioMix számolja, mi csak a kész PCM-mintákat adjuk tovább a kódolónak.
nonisolated struct MusicSource {
    let reader: AVAssetReader
    let output: AVAssetReaderAudioMixOutput

    static let fadeIn = 0.4
    static let fadeOut = 1.5

    /// - Parameters:
    ///   - url: zenefájl (m4a, mp3, wav, aiff, caf)
    ///   - start: honnan induljon (mp)
    ///   - volume: 0…1
    ///   - duration: a kész videó hossza (mp)
    static func make(url: URL, start: Double, volume: Double, duration: Double) async throws -> MusicSource {
        let asset = AVURLAsset(url: url)
        guard let track = try await asset.loadTracks(withMediaType: .audio).first else {
            throw RenderError.music("A fájlban nincs hang.")
        }
        let trackRange = try await track.load(.timeRange)
        let musicLength = trackRange.duration
        guard musicLength.isValid, musicLength.seconds > 0.2 else { throw RenderError.music("Túl rövid.") }

        let total = CMTime(seconds: duration, preferredTimescale: 48_000)
        let composition = AVMutableComposition()
        guard let lane = composition.addMutableTrack(withMediaType: .audio, preferredTrackID: kCMPersistentTrackID_Invalid) else {
            throw RenderError.music("Nem hozható létre hangsáv.")
        }
        // a kezdőpont: ha a zenénél hosszabb, az elejéről indul
        var from = CMTime(seconds: max(0, start), preferredTimescale: 48_000)
        if from >= musicLength - CMTime(seconds: 0.5, preferredTimescale: 48_000) { from = .zero }
        var cursor = CMTime.zero
        var guardCount = 0
        while cursor < total && guardCount < 500 {
            let available = musicLength - from
            let take = min(available, total - cursor)
            try lane.insertTimeRange(CMTimeRange(start: trackRange.start + from, duration: take), of: track, at: cursor)
            cursor = cursor + take
            from = .zero
            guardCount += 1
        }

        let level = Float(min(max(volume, 0), 1))
        let params = AVMutableAudioMixInputParameters(track: lane)
        params.setVolume(level, at: .zero)
        let fadeInTime = CMTime(seconds: min(Self.fadeIn, duration / 4), preferredTimescale: 48_000)
        params.setVolumeRamp(fromStartVolume: 0, toEndVolume: level, timeRange: CMTimeRange(start: .zero, duration: fadeInTime))
        let fadeOutTime = CMTime(seconds: min(Self.fadeOut, duration / 3), preferredTimescale: 48_000)
        if total > fadeOutTime + fadeInTime {
            params.setVolumeRamp(fromStartVolume: level, toEndVolume: 0,
                                 timeRange: CMTimeRange(start: total - fadeOutTime, duration: fadeOutTime))
        }
        let mix = AVMutableAudioMix()
        mix.inputParameters = [params]

        let reader = try AVAssetReader(asset: composition)
        let output = AVAssetReaderAudioMixOutput(audioTracks: [lane], audioSettings: [
            AVFormatIDKey: kAudioFormatLinearPCM,
            AVSampleRateKey: 48_000,
            AVNumberOfChannelsKey: 2,
            AVLinearPCMBitDepthKey: 16,
            AVLinearPCMIsFloatKey: false,
            AVLinearPCMIsBigEndianKey: false,
            AVLinearPCMIsNonInterleaved: false,
        ])
        output.audioMix = mix
        output.alwaysCopiesSampleData = false
        guard reader.canAdd(output) else { throw RenderError.music("Az olvasó nem fogadja.") }
        reader.add(output)
        reader.timeRange = CMTimeRange(start: .zero, duration: total)
        guard reader.startReading() else {
            throw RenderError.music(reader.error?.localizedDescription ?? "")
        }
        return MusicSource(reader: reader, output: output)
    }

    /// A kódoló beállításai: AAC, 48 kHz, sztereó, 192 kbit/s.
    static var writerSettings: [String: Any] {
        [
        AVFormatIDKey: kAudioFormatMPEG4AAC,
        AVSampleRateKey: 48_000,
        AVNumberOfChannelsKey: 2,
        AVEncoderBitRateKey: 192_000,
        ]
    }
}
