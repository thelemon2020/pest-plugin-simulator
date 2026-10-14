# An idle signal for NativePHP

This is a proposal for NativePHP (`nativephp/mobile`, checked against 4.6.0), and the patch that was used to check it. The plugin already reads the signal: see "Wait for the app, not the clock" in the README.

## The problem

A tap or a keystroke on a SuperNative screen goes from Swift or Kotlin to PHP as an event. PHP's runloop takes it, runs the handler, renders, and publishes the next tree. Nothing on screen says whether that round trip is still under way, so a test can only wait by looking: two reads that match, a fixed pause after a sheet appears, a field read twice.

Looking is not enough when PHP is slow. A `native:model` field sends PHP an event per keystroke and writes back what PHP answers. Typed in one burst, an answer that arrives after the next keystroke writes the older value back over the field. On the sample app's slow screen, where the PHP for each keystroke takes 300ms, "hello there" typed in one burst kept one letter in three tries.

## The signal

While the app runs a debug build, it shows one extra accessibility element:

- identifier `nativephp-pending` (the resource id on Android: a Compose `testTag`, with `testTagsAsResourceId`);
- label (Android: content description) the number of events the app has sent PHP that PHP has not taken yet;
- one point square, drawn with nothing, and it takes no touches.

The count is two counters, one on each side of the bridge:

- **posted**: the native side counts every event it hands to `nphp_element_post_event`.
- **taken**: PHP's runloop counts every event `nativephp_element_wait_event()` returns, and publishes the count on the root element as the prop `testing_events`. Every taken event is followed by a publish (the loop renders at the top of every pass), so the count reaches the native side with the tree that answers the event.

The native side shows `posted - taken`, updated on the main thread when an event is posted and when a tree is applied. When it reads 0, PHP has taken every event and the tree on screen is the one it published after the last of them.

## The change

Three small pieces. Below is the patch that was applied to a sample app's `vendor/nativephp/mobile` and generated `nativephp/` projects; upstream it belongs in `src/` and in `resources/xcode` and `resources/androidstudio`. Upstream it should also only be on in debug builds.

### PHP: count taken events and publish the count

```diff
--- a/src/Edge/NativeComponent.php
+++ b/src/Edge/NativeComponent.php
@@ -242,9 +242,17 @@
         return (nativephp_runtime_flags() & 0x02) !== 0;
     }
 
+    /**
+     * Testing: how many events the runloop has taken off the native event queue, across
+     * every screen. Published on the root element, so the native side can show a test
+     * runner how many events it has sent that PHP has not taken yet.
+     */
+    public static int $testingEventsTaken = 0;
+
     private function memoizedToArray(Element $element): array
     {
         $this->publishCount++;
+        $element->setProp('testing_events', self::$testingEventsTaken);
 
         $memoEnabled = $this->subtreeMemoEnabled();
         $nextId = 1;
@@ -2543,6 +2551,10 @@
             }
 
             $event = nativephp_element_wait_event($this->nextEventTimeout());
+
+            if ($event !== null) {
+                self::$testingEventsTaken++;
+            }
 
             if ($event === null) {
                 // Idle tick (poll interval elapsed, or no event yet) —
@@ -2756,6 +2768,10 @@
             }
 
             $event = nativephp_element_wait_event($this->nextEventTimeout());
+
+            if ($event !== null) {
+                self::$testingEventsTaken++;
+            }
 
             if ($event === null) {
                 // Idle tick (poll interval elapsed, or no event yet) —
```

### iOS: count posted events, read the taken count, show the marker

```diff
--- a/resources/xcode/NativePHP/NativeRender/NativeElementBridge.swift
+++ b/resources/xcode/NativePHP/NativeRender/NativeElementBridge.swift
@@ -520,6 +520,7 @@
                 }
                 if isFreshStackMount { NavigationCoordinator.shared.reset() }
                 bridge.currentTree = finalTree
+                TestingIdle.shared.didTake(finalTree.root.props.getInt("testing_events", default: 0))
                 // First publish after a hot-reload dismisses the
                 // "Reloading…" pill. Set by `HotReloadCoordinator.reload`
                 // at the start of the reboot; cleared here when the
@@ -673,6 +674,7 @@
     }
 
     private static func writeEvent(type: Int, callbackId: Int, nodeId: Int, data: Data?) {
+        TestingIdle.shared.didPost()
         // nodeId carries an FNV-1a 32-bit hash widened to Int — when bit
         // 31 is set the value exceeds Int32.max and the checked
         // `Int32(nodeId)` initializer traps. Use `truncatingIfNeeded`
--- a/resources/xcode/NativePHP/ContentView.swift
+++ b/resources/xcode/NativePHP/ContentView.swift
@@ -83,6 +83,9 @@
                 // WebKit processes for nothing.
                 screenBackground.ignoresSafeArea()
             }
+        }
+        .overlay(alignment: .topLeading) {
+            TestingIdleMarker()
         }
         .overlay(alignment: .top) {
             // Hot-reload indicator. Mirrors iOS 26's Liquid Glass pill
@@ -977,3 +980,55 @@
         }
     }
 }
+
+
+/// Testing: how many events the app has sent to PHP that PHP has not taken yet. The
+/// runloop publishes how many it has taken as the root's `testing_events` prop.
+final class TestingIdle: ObservableObject {
+    static let shared = TestingIdle()
+
+    @Published private(set) var pending = 0
+
+    private var posted = 0
+    private var taken = 0
+    private let lock = NSLock()
+
+    func didPost() {
+        lock.lock()
+        posted += 1
+        let value = max(0, posted - taken)
+        lock.unlock()
+        publish(value)
+    }
+
+    func didTake(_ count: Int) {
+        lock.lock()
+        taken = count
+        let value = max(0, posted - taken)
+        lock.unlock()
+        publish(value)
+    }
+
+    private func publish(_ value: Int) {
+        if Thread.isMainThread {
+            pending = value
+        } else {
+            DispatchQueue.main.async { self.pending = value }
+        }
+    }
+}
+
+/// The element a test runner reads: id `nativephp-pending`, label the count. One point,
+/// clear, and it lets every touch through.
+struct TestingIdleMarker: View {
+    @ObservedObject private var idle = TestingIdle.shared
+
+    var body: some View {
+        Color.clear
+            .frame(width: 1, height: 1)
+            .accessibilityElement()
+            .accessibilityIdentifier("nativephp-pending")
+            .accessibilityLabel(String(idle.pending))
+            .allowsHitTesting(false)
+    }
+}
```

### Android: the same

Every `nativeElementWriteEvent(...)` call in `NativeElementBridge.kt` goes through one function that counts first (fifteen call sites, all the same change):

```diff
--- a/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt
+++ b/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeElementBridge.kt
@@ -77,6 +77,12 @@
         @JvmStatic external fun nativeGetNodeCount(): Int
         @JvmStatic external fun nativeElementWriteEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?)
 
+        /** Testing: counts each event the app sends PHP, then sends it. */
+        @JvmStatic fun postEvent(type: Int, callbackId: Int, nodeId: Int, data: ByteArray?) {
+            TestingIdle.didPost()
+            nativeElementWriteEvent(type, callbackId, nodeId, data)
+        }
+
         /* Phase 0 — wire-format version + runtime feature flags
          * (NPHP_FORMAT_VERSION / NPHP_FLAG_* in nphp_element.h). */
         @JvmStatic external fun nativeGetFormatVersion(): Int
@@ -747,14 +753,14 @@
             val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
             buf.putFloat(x)
             buf.putFloat(y)
-            nativeElementWriteEvent(EventType.PRESS, callbackId, nodeId, buf.array())
+            postEvent(EventType.PRESS, callbackId, nodeId, buf.array())
         }
 
         fun sendLongPressEvent(callbackId: Int, nodeId: Int, x: Float = 0f, y: Float = 0f) {
             val buf = ByteBuffer.allocate(8).order(ByteOrder.LITTLE_ENDIAN)
             buf.putFloat(x)
             buf.putFloat(y)
-            nativeElementWriteEvent(EventType.LONG_PRESS, callbackId, nodeId, buf.array())
+            postEvent(EventType.LONG_PRESS, callbackId, nodeId, buf.array())
         }
 
         fun sendTextChangeEvent(callbackId: Int, nodeId: Int, text: String) {
@@ -763,14 +769,14 @@
             val buf = ByteBuffer.allocate(4 + textBytes.size).order(ByteOrder.LITTLE_ENDIAN)
             buf.putInt(textBytes.size)
             buf.put(textBytes)
-            nativeElementWriteEvent(EventType.TEXT_CHANGE, callbackId, nodeId, buf.array())
+            postEvent(EventType.TEXT_CHANGE, callbackId, nodeId, buf.array())
         }
 
```

```diff
--- a/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeUIBridge.kt
+++ b/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/nativerender/NativeUIBridge.kt
@@ -1,6 +1,16 @@
 package com.nativephp.mobile.ui.nativerender
 
 import android.util.Log
+import androidx.compose.foundation.layout.Box
+import androidx.compose.foundation.layout.size
+import androidx.compose.runtime.Composable
+import androidx.compose.ui.ExperimentalComposeUiApi
+import androidx.compose.ui.Modifier
+import androidx.compose.ui.semantics.contentDescription
+import androidx.compose.ui.semantics.semantics
+import androidx.compose.ui.semantics.testTag
+import androidx.compose.ui.semantics.testTagsAsResourceId
+import androidx.compose.ui.unit.dp
 import androidx.compose.runtime.mutableIntStateOf
 import androidx.compose.runtime.mutableLongStateOf
 import androidx.compose.runtime.mutableStateOf
@@ -24,6 +34,7 @@
         val treePublicationId = mutableLongStateOf(0L)
 
         internal fun publishTree(tree: NativeUITree) {
+            TestingIdle.didTake(tree.root.props.getInt("testing_events", 0))
             currentTree.value = tree
             treePublicationId.longValue++
         }
@@ -124,3 +135,47 @@
         }
     }
 }
+
+
+/**
+ * Testing: how many events the app has sent to PHP that PHP has not taken yet. The runloop
+ * publishes how many it has taken as the root's `testing_events` prop.
+ */
+object TestingIdle {
+    val pending = mutableIntStateOf(0)
+
+    private var posted = 0
+    private var taken = 0
+    private val lock = Any()
+    private val main = android.os.Handler(android.os.Looper.getMainLooper())
+
+    fun didPost() {
+        val value = synchronized(lock) { posted++; maxOf(0, posted - taken) }
+        main.post { pending.intValue = value }
+    }
+
+    fun didTake(count: Int) {
+        val value = synchronized(lock) { taken = count; maxOf(0, posted - taken) }
+        main.post { pending.intValue = value }
+    }
+}
+
+
+/**
+ * The element a test runner reads: resource id `nativephp-pending`, content description the
+ * count. One dp, drawn with nothing, and it takes no touches.
+ */
+@OptIn(ExperimentalComposeUiApi::class)
+@Composable
+fun TestingIdleMarker() {
+    val pending = TestingIdle.pending.intValue
+    Box(
+        Modifier
+            .size(1.dp)
+            .semantics {
+                testTagsAsResourceId = true
+                testTag = "nativephp-pending"
+                contentDescription = pending.toString()
+            }
+    )
+}
--- a/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt
+++ b/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt
@@ -207,6 +207,7 @@
                     // Splash overlay with fade animation (full screen, no insets).
                     // Lives here — NOT inside the showContent gate — so it paints on the
                     // first frame and covers the boot; MainScreen composes beneath it.
+                    com.nativephp.mobile.ui.nativerender.TestingIdleMarker()
                     AnimatedVisibility(
                         visible = showSplash,
                         exit = fadeOut(animationSpec = tween(300))
```

## How it was checked

The patch was applied to the sample app in this repo (`sample-app/`): to its `vendor/nativephp/mobile` and to its generated `nativephp/ios` and `nativephp/android` projects. The app was rebuilt, then run with the plugin on an iPhone 17 Pro (iOS 26.5) and an android-36 emulator. The test is `sample-app/tests/Simulator/SlowTest.php`: a field bound with `native:model` whose PHP takes 300ms per keystroke, typed with "hello there".

| | Without the marker | With the marker |
| --- | --- | --- |
| iOS | one letter kept after three tries | the whole string, typed one key at a time once PHP caught up |
| Android | not run without it | passes, in a full run of the suite (17 passed, 2 skipped for other reasons) |

Typing in one burst first, and a key at a time only on a retry, never settled on iOS: the burst starts the loop described first under "Also worth raising", and every retry waited for a count that stayed above 0. So on iOS the plugin types a key at a time from the start, which the sample app's form fields took in about 1.5 to 2 seconds more each than a burst. Android keeps the burst first. With that, SlowTest and the sample app's FormTest passed on both an iPhone 17 Pro Simulator (three runs) and an android-36 emulator.

Two things on Android were not about the signal itself:

- Compose reports a node a few pixels square as not visible to the user, and the plugin's screen reader skipped such nodes, so it now keeps the marker whatever its visibility. A marker NativePHP ships could be larger, or the plugin can keep reading it this way.
- At first the Android app ran none of the patched PHP. The app unpacks its Laravel bundle only when its version changes, and the sample app's stayed 1.0.0. The plugin now clears the unpacked copy after it builds (a separate change).

## Also worth raising

These came up while building the plugin's sample app, and each limits what a test on a device can do:

- **A `native:model` field on iOS can send PHP the same events forever.** When a value PHP answers differs from the last one the field sent, `NativeUITextInputCore.swift` writes it into `text`, and `.onChange(of: text)` then commits it as a new event. So once PHP answers late, each answer is older than what the field last sent, goes back to PHP, and comes back older again: the events go round and never drain. On an iPhone 17 Pro Simulator, after "hello there" went into the sample app's slow field in one burst, the marker read 11 for a whole minute while the field showed "h", "her", "he", "hell", "hello", "hello th" and so on in turn. A person typing faster than PHP answers starts the same loop. Android's renderers set a pushed value without calling `onValueChange`, so there a late answer only loses keys. Not committing a value that came from PHP would stop it.
- **A `ref` is not an accessibility id on the device.** `Native::test()` targets elements by `ref`, but nothing in the Swift or Kotlin renderers sets `accessibilityIdentifier` or a `testTag` from it, so a test on a device can only find a control by its label. Setting both from `ref` (debug builds at least) would let the same `tap('save-button')` work in-process and on a device.
- **A labelled container is not one element.** `<native:column a11y-label="Order 1">` does not become an accessibility container: its children are read on their own and the label goes nowhere. A container with an `a11y-label` exposed as one element that contains its children (`.accessibilityElement(children: .contain)`, Compose `semantics { }` without `mergeDescendants`) would let a test look inside one card.
- **A `<native:pressable>` drawn over content hides it from accessibility on Android.** On CollectShine's Wrapped story, two pressable tap zones in a `<native:stack>` cover the slides, and the slides' text never reaches the tree.
- **An intermittent cold-start crash on Android**: `IllegalStateException: Reading a state that was created after the snapshot was taken`, at `MainActivity.kt:1711` in `MainScreen`. Seen in CollectShine's emulator runs and again in the sample app's.
- **`@submit` on Android only answers the keyboard's own Done key.** The text input renderers use `KeyboardActions(onDone = ...)` and declare no IME action, so `ACTION_IME_ENTER` (which accessibility services and test tools send) and an Enter key event both reach the default action, which `onDone` ignores. Declaring `ImeAction.Done` (or answering `onAny`) would let a test, and a hardware keyboard, submit.
