import Foundation
import SpinKit

extension AppModel.Phase {
    /// Az állapot fajtája (a képernyőváltások animációjához – a folyamatjelző lépései ne animáljanak át).
    var kind: Int {
        switch self {
        case .idle: 0
        case .countdown: 1
        case .recording: 2
        case .processing: 3
        case .result: 4
        }
    }
}

extension SpinSettings {
    /// „Gyors – lassú – gyors 0,25× · oda-vissza · zene · keret”
    var effectSummary: String {
        var parts: [String] = [speedStyle == .normal ? "Normál sebesség" : "\(speedStyle.title) \(slowFactor.factorText)"]
        if boomerang { parts.append("oda-vissza") }
        if musicFile != nil { parts.append("zene") }
        if overlayFile != nil { parts.append("keret") }
        if !captionTop.isEmpty || !captionBottom.isEmpty { parts.append("felirat") }
        return parts.joined(separator: " · ")
    }
}

extension Double {
    /// „0:15”
    var clockText: String {
        let s = max(0, Int(self.rounded()))
        return String(format: "%d:%02d", s / 60, s % 60)
    }

    /// „0,3 mp”
    var tenthsText: String {
        String(format: "%.1f mp", self).replacingOccurrences(of: ".", with: ",")
    }
}

enum HungarianTime {
    static let locale = Locale(identifier: "hu_HU")

    static func time(_ date: Date) -> String {
        date.formatted(.dateTime.hour().minute().locale(locale))
    }
}
