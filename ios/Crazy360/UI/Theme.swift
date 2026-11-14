import SwiftUI

/// A Crazy Booth család színei (a mobil appból): sötétkék alap, narancs kiemelés. A narancs csak műveletet és állapotot jelöl.
enum Theme {
    /// #0E1220
    static let navy = Color(red: 14 / 255, green: 18 / 255, blue: 32 / 255)
    /// #141C33
    static let navy2 = Color(red: 20 / 255, green: 28 / 255, blue: 51 / 255)
    /// #E8894A – a fő kiemelés (tint)
    static let orange = Color(red: 232 / 255, green: 137 / 255, blue: 74 / 255)
    /// #F2B35B
    static let amber = Color(red: 242 / 255, green: 179 / 255, blue: 91 / 255)
    /// Felvétel (piros, mint a Kamera appban)
    static let record = Color(red: 1, green: 0.27, blue: 0.23)
    static let ok = Color(red: 0.36, green: 0.84, blue: 0.53)

    static let gradient = LinearGradient(colors: [orange, amber], startPoint: .leading, endPoint: .trailing)
}

/// Fő gomb: narancs átmenet, sötétkék felirat (a mobil app „pri” gombja).
struct PrimaryButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(Theme.navy2)
            .frame(maxWidth: .infinity, minHeight: 56)
            .background(Theme.gradient, in: Capsule())
            .opacity(isEnabled ? (configuration.isPressed ? 0.85 : 1) : 0.4)
            .scaleEffect(configuration.isPressed ? 0.98 : 1)
            .animation(.easeOut(duration: 0.15), value: configuration.isPressed)
    }
}

/// Kerek ikon-gomb a kamerakép fölött (rendszer-anyag háttérrel, 52 pt).
struct OverlayIconButton: View {
    let systemImage: String
    let label: String
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: systemImage)
                .font(.title3.weight(.semibold))
                .frame(width: 52, height: 52)
                .background(.ultraThinMaterial, in: Circle())
        }
        .buttonStyle(.plain)
        .foregroundStyle(.white)
        .accessibilityLabel(label)
    }
}

extension Double {
    /// „8 mp”, „1:05”
    var secondsText: String {
        let s = Int(self.rounded())
        return s < 60 ? "\(s) mp" : String(format: "%d:%02d", s / 60, s % 60)
    }

    /// „0,25×” – magyar tizedesvesszővel.
    var factorText: String {
        let s = self == 1.0 / 3.0 ? "0,33" : String(format: "%g", (self * 100).rounded() / 100).replacingOccurrences(of: ".", with: ",")
        return s + "×"
    }
}
