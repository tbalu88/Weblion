import SpinKit
import SwiftUI

/// Rendezvény a panel listájából (nap szerint), vagy „Rendezvény nélkül”.
struct EventPickerView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var day = Date()
    @State private var state: LoadState = .loading

    private enum LoadState {
        case loading
        case loaded([GalleryEvent])
        case failed(String)
    }

    var body: some View {
        Form {
            Section {
                DatePicker("Nap", selection: $day, displayedComponents: .date)
            }
            Section {
                switch state {
                case .loading:
                    HStack(spacing: 12) {
                        ProgressView()
                        Text("Betöltés…").foregroundStyle(.secondary)
                    }
                case .failed(let message):
                    VStack(alignment: .leading, spacing: 8) {
                        Text(message).foregroundStyle(Theme.orange)
                        Button("Újra") { Task { await load() } }
                            .buttonStyle(.borderless)
                    }
                case .loaded(let list) where list.isEmpty:
                    Text("Erre a napra nincs foglalt rendezvény a panelben.")
                        .foregroundStyle(.secondary)
                case .loaded(let list):
                    ForEach(list) { event in
                        Button {
                            model.event = event
                            dismiss()
                        } label: {
                            EventRow(event: event, selected: event.ev == model.event?.ev)
                        }
                    }
                }
            } header: {
                Text("A panel rendezvényei")
            }
            Section {
                Button {
                    model.event = .standalone(date: DayString.from(day))
                    dismiss()
                } label: {
                    Label("Rendezvény nélkül", systemImage: "square.dashed")
                }
            } footer: {
                Text("Saját galéria készül, dealhez kötés nélkül. A panel Galériák menüjében később is megtalálod.")
            }
        }
        .navigationTitle("Rendezvény")
        .navigationBarTitleDisplayMode(.inline)
        .task(id: day) { await load() }
        .refreshable { await load() }
    }

    private func load() async {
        state = .loading
        do {
            state = .loaded(try await model.events(on: day))
        } catch {
            state = .failed(error.localizedDescription)
        }
    }
}

private struct EventRow: View {
    let event: GalleryEvent
    let selected: Bool

    var body: some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(event.title)
                    .foregroundStyle(.primary)
                    .lineLimit(2)
                let details = [event.time, event.loc].compactMap { $0 }.joined(separator: " · ")
                if !details.isEmpty {
                    Text(details)
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 8)
            if selected {
                Image(systemName: "checkmark")
                    .fontWeight(.semibold)
                    .foregroundStyle(Theme.orange)
                    .accessibilityLabel("Kiválasztva")
            }
        }
        .contentShape(Rectangle())
    }
}
