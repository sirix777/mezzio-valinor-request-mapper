# Task5 post-data cleanup delta — scoped revisions required

review_task5_cleanup: SpecCompliant NeedsRevision / TaskQualityApproved NeedsRevision.
security_task5_cleanup: NeedsRevision, one medium bounded-cleanup finding (security gate warn, not production signoff).

Confirmed P2 shareddeadline overrun: new cleanup discovery/inspect/empty reads pass phase remaining to pilotCommand, which adds timeout --kill-after=1s; pilotChild teardown also adds50ms. Inert directhelper phase0.3s took1.303188305s; independent security TERM-ignoring timeout0.1s took1.10s. Existing already-expired zero-launch case does not cover running elapsedphase. Must include alltermination/teardown inside existingONE5s/22reserve, no global budget extension or nativeguard relaxation.

Confirmed P2 missing disappearance/network-only coverage: supplied-IDinspect disappearance throws beforeowndown, betweeninspect/removal disappearance untested; currentcontainer-leftover test shortcircuitsnetworkcheck. Root ruling requires own down still attempted on disappearance/unproven inspectfailure with NOunsafe rm; verifiedforeign/reusedidentity rejection preserved. Only exactemptyownnetwork residue may be removed afterstrictfullID/project/networklabels/membervalidation. Nonempty/foreign/unverified resources neverremoved.

Other scoped ownership/image/arguments/localbinding/salvageordering/failurepreservation checks acceptable; package/source/archive/manifest hashes matched. Broader study/report/nativecleanup/fullsecurity/HTTP/performance acceptance ruled laterparent-owned, not silentlyomitted. No source/native/HTTP modifications by either reviewer.

Root authorized onecombinedMINIMALofflineTDD fix of ONLYcleanup helper/existingtests, meaningful RED/GREEN for disappeared-before-inspect and inspect→rm, failedunproveninspect, network-onlyresidue, nearexpiry+TERM-ignore allgrace charged≤5s, foreign/reusedsafe. New separateUNEXECUTEDfollowup package/isolated13serialsuite/cleanreplay and freshscoped code+security rereviews required. Executed6acbb050 manifest/21bd7204 archive/raw/original7cleanup.logs immutable; no pilot/native/HTTP/cellretry.
