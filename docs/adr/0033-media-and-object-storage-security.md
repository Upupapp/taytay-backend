# ADR 0033 — Media and object-storage security

* **Status:** accepted
* **Date:** 2026-08-16
* **Built in:** TAB 28
* **Related:** ADR 0020 (files and documents), ADR 0022 §1 (the location refusal), ADR 0028 §7
  (alt text), ADR 0030 §3 (a cancelled event stays visible), ADR 0004 (topology)

---

## Context

TAB 15 built the private side of storage well: opaque keys, content sniffing from leading bytes, a
size ceiling, a checksum, a scanner seam, and one disk that is always private. What it did not
build is the other half — because until TABs 23 and 25 there was no published content to serve.

The newsfeed and events now carry images that **anonymous people need to see**, and every naive
route to that is a bad one:

* serve the uploaded file through the authenticated endpoint → anonymous readers cannot see it;
* make the private bucket public → every KYC photograph in it becomes public;
* copy the upload to a public bucket on publication → a resident's photograph, complete with the
  GPS coordinates of their house, is now at a permanent URL.

The third is the one that would actually have been built, and it is the one this ADR exists to
prevent.

---

## 1. There is no promotion. There is derivation.

**Publication does not move, copy, promote or re-permission the uploaded file.** It produces a
*new* object by re-encoding the image, and writes that to a separate bucket. The original stays on
the private disk for its entire life.

That single decision satisfies the master command's "do not place sensitive attachments in a public
bucket even temporarily" without anybody having to follow a rule — there is no code path that could
put an uploaded byte there.

---

## 2. EXIF is not stripped. It is never present.

This is the most important paragraph in the ADR, because the two approaches look identical from
outside and fail completely differently.

**Stripping** means taking the original bytes and removing the metadata segments. That requires
knowing every segment that can carry a location. JPEG alone can hold coordinates in EXIF, in XMP
and in an IPTC block; PNG can hold them in `eXIf`, `tEXt` and `iTXt`. A stripper that knows five of
six leaks, and it leaks silently, and the sixth is whatever a phone vendor adds next year.

**Re-encoding** decodes the file to a raw pixel buffer and writes a new file from it. The buffer is
width × height × colour. **There is nowhere for a coordinate to be.** The output has no metadata
not because anything removed it but because the intermediate representation cannot express it — and
a metadata format invented next year changes nothing.

`ImageDerivative` does the second. The acceptance criterion is not "we remembered to strip"; it is
structural.

### The trap: orientation must be baked in first

A phone photographed sideways is stored upright with an EXIF `Orientation` tag saying "rotate me".
Re-encoding drops that tag — so an image that displayed correctly *before* processing displays on
its side *after*, and nothing looks wrong because the file is genuinely valid.

So the rotation is read from the source and baked into the pixels before the re-encode. That is
also exactly what the master command means by "image dimension/orientation normalization".

Only the four rotations are handled. The mirrored orientations (2, 4, 5, 7) essentially never occur
outside deliberately crafted files, and treating one as its unmirrored equivalent shows the image
the right way up — better than four more branches nobody can test against a real photograph.

### No fallback to the original

If GD is missing or the bytes will not decode, **no public object is produced**. The content
publishes without an image.

A fallback that copied the original instead would put an EXIF-carrying file in a public bucket on
exactly the one host where the image library was missing — the hardest possible failure to notice,
because everywhere else it would work correctly.

### Always JPEG, whatever came in

One output format means one encoder to reason about, and the PNG chunk types that can carry text
are simply never written. Transparency is flattened onto white rather than lost to black, which is
what an unflattened PNG-to-JPEG conversion produces and what makes a logo look like a mistake.

### No public PDF path

A PDF cannot be re-encoded through a pixel buffer, so its metadata would have to be *stripped* —
and see above. `MediaPublisher` refuses the whole format.

---

## 3. Publication is the only route, and it runs in both directions

`DocumentLibrary::publishMedia()` reads as a **side effect of publication**, not as an operation
somebody performs. There is deliberately no "make this file public" verb, because a verb like that
is one somebody eventually calls on a file whose content was never published.

Four gates, all of which must pass, and **all of them live in Files rather than in the caller**:

1. the classification must be `public-reference` — personal, sensitive and operational material is
   refused outright, so a module that attached a KYC photograph to a post cannot publish it by
   being wrong, and the module owning the content is exactly the one least able to judge what the
   file contains;
2. the scan must not have failed;
3. it must be an image;
4. the derivation must succeed.

**Withdrawal matters more than publication.** A post archived or an event un-published whose image
stayed at a public URL would be a takedown that did not take anything down — and the URL is the
part that gets shared, screenshotted and indexed. Both the objects and the rows are deleted.

Driven by the content's own visibility predicate (`NewsfeedPost::isLive()`,
`EventStatus::isPubliclyVisible()`) rather than by which transition was requested, so a state added
to either enum later cannot leave an image public on content nobody can see.

**A cancelled event keeps its cover.** It stays on the public list with its reason showing
(ADR 0030 §3), and a listing that lost its image the moment it was called off would look broken to
exactly the people who most need to read it.

Publication runs **outside** the transition transaction. Re-encoding is slow, and a failure to
resize a poster must not roll back the publication of an advisory: the post is live either way, and
a missing thumbnail is a smaller problem than an announcement that silently did not go out.

Publishing is idempotent — a post edited and republished, or a job retried, must not accumulate
objects.

---

## 4. Two buckets, two credentials, one writer

`object-storage` (private) and `public-media` (public). **Separate buckets with separate
credentials, not a public prefix inside the private one.** The arrangements look equivalent and are
not: a single misapplied policy on a shared bucket exposes everything in it, so the blast radius of
one mistake is the whole store rather than some already-published images. Least-privilege keys mean
the credential that can write derived media cannot read a KYC document.

`PublicMediaHasOneWriterTest` makes the "one writer" structural: no file under `modules/` may name
the public disk except `MediaPublisher` and the enum that maps a visibility to a disk. Comments are
stripped with the PHP tokeniser first, because every docblock here discusses the disk by name and a
detector that matched its own explanation would have to be silenced.

It also asserts that no disk this application declares publishes a base `url` except the public
ones — a `url` on the private store is the single setting that makes an object reachable without an
authorization decision, and it is one line somebody adds while debugging.

And it notes, without failing, that Laravel's default `s3` disk is inert. This project never uses
it, but it publishes a base URL from `AWS_URL`, so the day somebody sets those variables for an
unrelated reason the deployment gains a URL-publishing disk nobody reviewed.

Public storage keys are opaque, which matters twice over on a public bucket: a guessable key is a
directory listing for anybody who wants one, and a filename-derived key would publish what a
resident called the file.

---

## 5. Size limits are per context

One global ceiling is wrong at both ends. A multi-page scanned certificate genuinely needs several
megabytes, and refusing it sends a resident back to a photocopier; an advisory image for the public
feed needs a fraction of that, and a generous limit there is an invitation to put a 10 MB
photograph on a page people open on mobile data.

`FileClassification::maxBytes()` — 4 MiB for `public-reference`, 8 for `operational`, 10 for
`personal` and `sensitive`. `AcceptedMediaType::MAX_BYTES` remains the absolute ceiling no
classification may exceed, and **every value must stay below the reverse proxy's
`client_max_body_size`**: if nginx rejects the body first it answers 413 without running PHP, and
therefore without CORS headers, so a browser sees a network failure with status 0 rather than a
message anybody can act on.

---

## 6. Duplicate detection reports; it does not refuse

The master command asks for a checksum to detect *accidental* duplicate uploads. Refusing the second
upload would be wrong: re-sending one barangay clearance against a second requirement is
legitimate, and a household sharing a scanned certificate is normal.

So `duplicate_of_file_id` records it and the upload succeeds. What the office wants is to be told —
so a console can say "this is the file you sent on Tuesday" — not to have the resident blocked.

Scoped to the same uploader. Two residents happening to submit an identical blank form is not a
duplicate worth pointing at, and linking their records across that coincidence would be a small
disclosure of one to the other.

---

## 7. Two variants, and not more

`thumbnail` (400px longest edge) and `web` (1280px). Every variant is bytes somebody stores, a job
somebody runs and an object somebody must remember to delete when the content is withdrawn. A
"small / medium / large / original / square / retina" ladder is six of each and five are never
requested.

The **longest** edge is constrained, not a fixed box: a poster is portrait, a group photograph is
landscape, and this keeps both proportional without cropping a face out of one of them. Smaller
images are never enlarged — upscaling a blurry photograph produces a bigger blurry photograph and a
larger file to send to a phone.

---

## 8. What this TAB did not build

* **A malware scanner implementation.** Still G-25; the seam, the state machine and the download
  consequences were all wired in TAB 15.
* **Queued derivation.** Publication derives inline today. It is fast for two variants of one image
  and slow for a post with ten, and moving it to a job is a one-line change behind the same
  interface — recorded as **G-45** rather than done speculatively.
* **A CDN in front of the public bucket.** An operational choice, not a code one.
* **Retention purging of derived objects.** The originals have a retention schedule (G-25); the
  derived objects are deleted on withdrawal, which is the case that matters.

---

## Consequences

* An advisory image published to the feed is a JPEG this system encoded, at most 1280px, carrying
  nothing but pixels. Some fidelity is lost. That is the trade, and it is the right one.
* Publishing a post with images is slower than publishing one without (**G-45**).
* An environment without GD publishes content without images rather than publishing originals. That
  is loud in the wrong way — a missing picture — which is the correct direction for this failure.
