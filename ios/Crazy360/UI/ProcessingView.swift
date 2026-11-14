import SwiftUI

/// Feldolgozás: nagy százalék és sáv – karnyújtásnyiról is látszik, mennyi van hátra.
struct ProcessingView: View {
    let progress: Double
    let summary: String

    var body: some View {
        ZStack {
            Theme.navy.ignoresSafeArea()
            VStack(spacing: 18) {
                Text("Készül a videó")
                    .font(.title2.bold())
                Text("\(Int((progress * 100).rounded()))%")
                    .font(.system(size: 64, weight: .bold, design: .rounded))
                    .monospacedDigit()
                    .contentTransition(.numericText())
                    .animation(.easeOut(duration: 0.2), value: Int(progress * 100))
                ProgressView(value: progress)
                    .tint(Theme.orange)
                    .frame(maxWidth: 280)
                Text(summary)
                    .font(.subheadline)
                    .foregroundStyle(.white.opacity(0.7))
                    .multilineTextAlignment(.center)
            }
            .padding(32)
        }
        .foregroundStyle(.white)
        .accessibilityElement(children: .combine)
    }
}
