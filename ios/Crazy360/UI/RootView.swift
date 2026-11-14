import SwiftUI

/// A kamera mindig alul van; fölötte a feldolgozás és az eredmény. A beállítások és a videólista lapként jön.
struct RootView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var showSettings = false
    @State private var showLibrary = false

    var body: some View {
        ZStack {
            CaptureScreen(triggerEnabled: !showSettings && !showLibrary,
                          openSettings: { showSettings = true },
                          openLibrary: { showLibrary = true })
            if case .processing(let progress) = model.phase {
                ProcessingView(progress: progress, summary: model.settings.effectSummary)
                    .transition(.opacity)
            }
            if case .result(let id) = model.phase {
                ResultView(clipID: id)
                    .transition(reduceMotion ? .opacity : .opacity.combined(with: .scale(scale: 0.98)))
            }
        }
        .animation(.easeOut(duration: 0.25), value: model.phase.kind)
        .sheet(isPresented: $showSettings) {
            SettingsView()
        }
        .sheet(isPresented: $showLibrary) {
            NavigationStack {
                LibraryView()
                    .toolbar {
                        ToolbarItem(placement: .confirmationAction) {
                            Button("Kész") { showLibrary = false }
                        }
                    }
            }
        }
        .statusBarHidden()
        .persistentSystemOverlays(.hidden)
    }
}
