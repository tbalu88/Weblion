import SpinKit
import SwiftUI
import UIKit

/// A telefonon lévő videók (legújabb elöl), feltöltési állapottal.
struct LibraryView: View {
    @Environment(AppModel.self) private var model
    @State private var pendingDelete: Clip?

    var body: some View {
        List {
            ForEach(model.clips.clips) { clip in
                NavigationLink(value: clip.id) {
                    ClipRow(clip: clip)
                }
                .swipeActions(edge: .trailing) {
                    Button(role: .destructive) {
                        pendingDelete = clip
                    } label: {
                        Label("Törlés", systemImage: "trash")
                    }
                    if !clip.isUploaded {
                        Button {
                            model.retry(clip.id)
                        } label: {
                            Label("Újra", systemImage: "arrow.clockwise")
                        }
                        .tint(Theme.orange)
                    }
                }
            }
        }
        .overlay {
            if model.clips.clips.isEmpty {
                ContentUnavailableView("Még nincs videó", systemImage: "video",
                                       description: Text("A felvételek itt jelennek meg, a feltöltés állapotával."))
            }
        }
        .navigationTitle("Videók")
        .navigationDestination(for: String.self) { id in
            ClipDetailView(clipID: id)
        }
        .confirmationDialog("Törlöd a videót erről a telefonról?",
                            isPresented: Binding(get: { pendingDelete != nil }, set: { if !$0 { pendingDelete = nil } }),
                            titleVisibility: .visible, presenting: pendingDelete) { clip in
            Button("Törlés a telefonról", role: .destructive) { model.delete(clip.id) }
        } message: { clip in
            Text(clip.isUploaded ? "A galériában megmarad." : "Még nincs feltöltve – a galériába sem kerül fel.")
        }
    }
}

struct ClipRow: View {
    @Environment(AppModel.self) private var model
    let clip: Clip

    var body: some View {
        HStack(spacing: 12) {
            PosterThumbnail(url: model.posterURL(clip))
                .frame(width: 54, height: 96)
                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
            VStack(alignment: .leading, spacing: 4) {
                Text("\(HungarianTime.time(clip.createdAt)) · \(clip.duration.secondsText)")
                    .font(.headline)
                    .monospacedDigit()
                Text(clip.eventTitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                UploadStatusLabel(clip: clip)
                    .font(.footnote)
                    .lineLimit(2)
            }
        }
        .padding(.vertical, 4)
    }
}

/// Egy videó részletei: lejátszás, QR, újrapróbálás, megosztás, törlés.
struct ClipDetailView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    let clipID: String
    @State private var confirmDelete = false

    var body: some View {
        if let clip = model.clips[clipID] {
            ScrollView {
                VStack(spacing: 16) {
                    LoopingPlayer(url: model.videoURL(clip), muted: false)
                        .aspectRatio(9.0 / 16.0, contentMode: .fit)
                        .frame(maxHeight: 420)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                    QRCard(clip: clip)
                    if !clip.isUploaded {
                        Button("Feltöltés most") { model.retry(clip.id) }
                            .buttonStyle(PrimaryButtonStyle())
                    }
                    ShareLink(item: model.videoURL(clip)) {
                        Label("Videó megosztása (AirDrop, üzenet…)", systemImage: "square.and.arrow.up")
                            .frame(maxWidth: .infinity, minHeight: 50)
                    }
                    .buttonStyle(.bordered)
                    Button("Törlés a telefonról", role: .destructive) { confirmDelete = true }
                        .frame(minHeight: 44)
                }
                .padding(16)
            }
            .navigationTitle(HungarianTime.time(clip.createdAt))
            .navigationBarTitleDisplayMode(.inline)
            .confirmationDialog("Törlöd a videót erről a telefonról?", isPresented: $confirmDelete, titleVisibility: .visible) {
                Button("Törlés a telefonról", role: .destructive) {
                    model.delete(clip.id)
                    dismiss()
                }
            } message: {
                Text(clip.isUploaded ? "A galériában megmarad." : "Még nincs feltöltve – a galériába sem kerül fel.")
            }
        } else {
            ContentUnavailableView("A videó már nincs meg", systemImage: "video.slash")
        }
    }
}

/// Borítókép kicsinyítve, a háttérben betöltve.
struct PosterThumbnail: View {
    let url: URL?
    @State private var image: UIImage?

    var body: some View {
        ZStack {
            Theme.navy2
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFill()
            } else {
                Image(systemName: "video")
                    .foregroundStyle(.secondary)
            }
        }
        .clipped()
        .task(id: url) { image = await Self.load(url) }
    }

    static func load(_ url: URL?) async -> UIImage? {
        guard let url, let full = UIImage(contentsOfFile: url.path) else { return nil }
        return await full.byPreparingThumbnail(ofSize: CGSize(width: 216, height: 384))
    }
}
