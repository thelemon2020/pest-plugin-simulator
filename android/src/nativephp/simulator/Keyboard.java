package nativephp.simulator;

import android.app.UiAutomation;
import android.os.Bundle;
import android.os.SystemClock;
import android.view.InputDevice;
import android.view.InputEvent;
import android.view.KeyCharacterMap;
import android.view.KeyEvent;
import android.view.accessibility.AccessibilityNodeInfo;
import android.view.accessibility.AccessibilityWindowInfo;

import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.List;

/**
 * Types for AndroidDriver from the reader's process, so no `input` process starts per
 * character.
 *
 * Printable ASCII is typed as key presses. Anything else is pasted (see Clipboard), a new
 * line too: Enter would submit a field, or move on to the next one.
 *
 * Keys go to the input manager, as `input` sends them, and each waits until the app has
 * handled it. UiAutomation.injectInputEvent also has the window manager sync its
 * transactions around every key, which cost about 60 ms a character on an emulator.
 *
 * NativePHP's text field sends each change to PHP and takes back what PHP renders. A render
 * of an older value that comes back after a newer key replaces the text with that value,
 * until the render of the newer one comes back. A key that lands in between is typed onto
 * the older value, and the key before it is lost. So each key, and each paste, waits until
 * the field shows everything typed before it, and then until it shows that key or paste
 * too, after a pause that doubles whenever the field showed an older render. A render
 * already on its way when the field is read can still land first, so a lost key is put
 * right (see type()). A paste is typed in the same type() as the keys around it, because
 * only that call knows what the field should show before it.
 *
 * On CollectShine's sign-in screen, 20 characters typed with no pause lost letters every
 * time. A fixed pause alone lost one run in fifteen at 40 ms, and lost a letter at 60 ms
 * with the emulator's host busy. Waiting for the field, at about 60 ms a key, still lost a
 * key in one of 53 runs, 10 of them with six of the host's ten cores kept busy. With lost
 * keys put right, all of 40 more runs ended with the whole text.
 */
final class Keyboard {
    // InputManager.INJECT_INPUT_EVENT_MODE_WAIT_FOR_FINISH
    private static final int WAIT_FOR_FINISH = 2;

    private static final long LONGEST_PAUSE = 480;

    private static final int SHOWN = 0;

    private static final int OLDER = 1;

    private static final int LOST = -1;

    private static final int GONE = -2;

    // Longer than the keyboard takes to slide away after Back, about 0.4 s on an emulator.
    private static final long SLIDE = 1000;

    private final UiAutomation automation;

    private final KeyCharacterMap keys = KeyCharacterMap.load(KeyCharacterMap.VIRTUAL_KEYBOARD);

    private final Clipboard clipboard = Clipboard.connect();

    private Object manager;

    private Method inject;

    private long reached;

    private long backAt;

    Keyboard(UiAutomation automation) {
        this.automation = automation;

        try {
            Class<?> managerClass = managerClass();
            manager = managerClass.getMethod("getInstance").invoke(null);
            inject = managerClass.getMethod("injectInputEvent", InputEvent.class, int.class);
        } catch (Exception exception) {
            inject = null;
        }
    }

    /**
     * Android 14 moved injectInputEvent from InputManager to InputManagerGlobal.
     */
    private static Class<?> managerClass() throws ClassNotFoundException {
        try {
            return Class.forName("android.hardware.input.InputManagerGlobal");
        } catch (ClassNotFoundException exception) {
            return Class.forName("android.hardware.input.InputManager");
        }
    }

    /**
     * Types the text into the focused field: key presses, and a paste for each run of
     * characters with no key (see the class). False, with nothing typed, when the key map
     * has no key for a printable character, or the text has something to paste and the
     * clipboard cannot be set.
     *
     * A key that landed on an older render is lost for good: the renders still to come end
     * on the text with the key missing. Then the field is set to the whole text in one
     * change instead, which the renders still to come cannot undo, as the last of them is
     * that text. So is a field that does not show a key within a second, because it caps or
     * reformats what is typed. With no focused field to read, or once it is gone, the keys
     * are typed without looking. The next call starts at half the pause this one reached,
     * so typing again after an older render goes slower.
     */
    boolean type(String text, long pause) {
        List<Piece> pieces = pieces(text);

        if (pieces == null) {
            return false;
        }

        // Setting the clipboard before the first key, so a clipboard that refuses it leaves
        // nothing typed.
        for (Piece piece : pieces) {
            if (piece.keys == null) {
                if (clipboard == null || !clipboard.set(piece.text)) {
                    return false;
                }

                break;
            }
        }

        pause = Math.max(pause, reached / 2);
        AccessibilityNodeInfo field = focusedField();
        String start = field == null ? null : value(field);

        if (start == null) {
            field = null;
            start = "";
        }

        String typed = start;

        for (Piece piece : pieces) {
            SystemClock.sleep(pause);
            String next = typed + piece.text;
            int before = field == null ? SHOWN : await(field, typed, null);

            if (!enter(piece)) {
                return false;
            }

            int after = field == null ? SHOWN : await(field, next, typed);

            if (before == GONE || after == GONE) {
                field = null;
            } else if (before != SHOWN || after != SHOWN) {
                pause = Math.min(Math.max(pause, 10) * 2, LONGEST_PAUSE);
            }

            if (before == LOST || after == LOST) {
                reached = pause;
                set(field, start + text);
                until(field, start + text);

                return true;
            }

            typed = next;
        }

        reached = pause;

        return true;
    }

    /**
     * A key press for each printable ASCII character, and one paste for each run of the
     * rest. Null when the key map has no key for a printable character.
     */
    private List<Piece> pieces(String text) {
        List<Piece> pieces = new ArrayList<>();
        StringBuilder paste = new StringBuilder();

        for (int i = 0; i < text.length(); i++) {
            char character = text.charAt(i);

            if (character < ' ' || character > '~') {
                paste.append(character);

                continue;
            }

            KeyEvent[] events = keys.getEvents(new char[]{character});

            if (events == null || events.length == 0) {
                return null;
            }

            if (paste.length() > 0) {
                pieces.add(new Piece(paste.toString(), null));
                paste.setLength(0);
            }

            pieces.add(new Piece(String.valueOf(character), events));
        }

        if (paste.length() > 0) {
            pieces.add(new Piece(paste.toString(), null));
        }

        return pieces;
    }

    /**
     * Presses the piece's keys, or puts its text on the clipboard and presses Paste, which
     * the field pastes at its cursor as it would from a hardware keyboard.
     */
    private boolean enter(Piece piece) {
        if (piece.keys == null) {
            return clipboard.set(piece.text)
                    && send(KeyEvent.ACTION_DOWN, KeyEvent.KEYCODE_PASTE, 0)
                    && send(KeyEvent.ACTION_UP, KeyEvent.KEYCODE_PASTE, 0);
        }

        for (KeyEvent event : piece.keys) {
            if (!send(event.getAction(), event.getKeyCode(), event.getMetaState())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Waits for the field to show $text. SHOWN when it did, showing at most $before on the
     * way, the text before the key just typed. OLDER when it showed an older render, a
     * beginning of $text, LOST when it showed anything else, or not $text within a second,
     * and GONE when the field is not there to read.
     */
    private static int await(AccessibilityNodeInfo field, String text, String before) {
        long deadline = SystemClock.uptimeMillis() + 1000;
        int shown = SHOWN;
        String value;

        while (!shows(value = value(field), field, text)) {
            if (value == null) {
                return GONE;
            }

            if (SystemClock.uptimeMillis() > deadline || !older(value, field, text)) {
                return LOST;
            }

            if (before == null || !shows(value, field, before)) {
                shown = OLDER;
            }

            SystemClock.sleep(5);
        }

        return shown;
    }

    /**
     * Waits, up to a second, for the field to show $text, through whatever renders come
     * back on the way.
     */
    private static void until(AccessibilityNodeInfo field, String text) {
        long deadline = SystemClock.uptimeMillis() + 1000;

        while (!shows(value(field), field, text) && SystemClock.uptimeMillis() < deadline) {
            SystemClock.sleep(5);
        }
    }

    /**
     * Whether the field shows what it held before part of the text: a beginning of it. A
     * password field reads back as bullets, so there only its length tells.
     */
    private static boolean older(String value, AccessibilityNodeInfo field, String text) {
        return field.isPassword() ? value.length() < text.length() : text.startsWith(value);
    }

    /**
     * A password field reads back one bullet per character, so only its length is compared.
     */
    private static boolean shows(String value, AccessibilityNodeInfo field, String text) {
        if (value == null) {
            return false;
        }

        return field.isPassword() ? value.length() == text.length() : value.equals(text);
    }

    /**
     * The field's text, fetched fresh. A field that is gone reads as null, which no text
     * matches.
     */
    private static String value(AccessibilityNodeInfo field) {
        if (!field.refresh()) {
            return null;
        }

        CharSequence text = field.isShowingHintText() ? null : field.getText();

        return text == null ? "" : text.toString();
    }

    /**
     * Empties the focused field in one change, as UiAutomator's UiObject2.clear() does.
     * Ctrl+A sent as keys reached the software keyboard first, and the delete after it took
     * one character. It waits, up to a second, for the field to read back empty, so typing
     * right after starts from the empty field. False when no field is focused.
     */
    boolean clear() {
        AccessibilityNodeInfo field = focusedField();

        if (field == null) {
            return false;
        }

        if (!set(field, "")) {
            return false;
        }

        until(field, "");

        return true;
    }

    private static boolean set(AccessibilityNodeInfo field, String text) {
        Bundle arguments = new Bundle();
        arguments.putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, text);

        return field.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, arguments);
    }

    /**
     * Performs the focused field's IME action, ACTION_IME_ENTER: what a field's keyboard
     * action key does.
     *
     * NativePHP 4.6 runs a Compose field's @submit only from KeyboardActions(onDone), and
     * its fields declare no IME action, so this and a KEYCODE_ENTER key event both reach the
     * field's default action, which onDone does not answer. Only a tap on the keyboard's
     * own Done key submitted, and Gboard does not show its keys to accessibility without
     * TalkBack. A field that declares ImeAction.Done, or answers onAny, submits from this.
     */
    boolean enter() {
        AccessibilityNodeInfo field = focusedField();

        return field != null && field.performAction(AccessibilityNodeInfo.AccessibilityAction.ACTION_IME_ENTER.getId());
    }

    /**
     * The focused field, fetched fresh. Compose can answer findFocus() with the field that
     * was focused before the last tap, which then says it is not focused, and the tree is
     * searched instead.
     */
    private AccessibilityNodeInfo focusedField() {
        AccessibilityNodeInfo field = automation.findFocus(AccessibilityNodeInfo.FOCUS_INPUT);

        if (field != null && field.refresh() && field.isFocused() && field.isEditable()) {
            return field;
        }

        AccessibilityNodeInfo root = automation.getRootInActiveWindow();

        return root != null && root.refresh() ? focused(root) : null;
    }

    private static AccessibilityNodeInfo focused(AccessibilityNodeInfo node) {
        if (node.isFocused() && node.isEditable()) {
            return node;
        }

        for (int i = 0; i < node.getChildCount(); i++) {
            AccessibilityNodeInfo child = node.getChild(i);

            if (child != null && child.refresh()) {
                AccessibilityNodeInfo field = focused(child);

                if (field != null) {
                    return field;
                }
            }
        }

        return null;
    }

    /**
     * Back closes only the software keyboard while it is up, and leaves the field focused.
     * The keyboard's window is on screen while it is up.
     *
     * The window also stays while the keyboard slides away, and `dumpsys input_method`
     * still says it is shown, so a hide() soon after the last Back would press Back again,
     * and that Back leaves the screen. So for a second after its Back, hide() waits for the
     * window to go, and presses Back only when it stays: typing opened the keyboard again.
     * False when Back could not be pressed.
     */
    boolean hide() {
        while (keyboardUp() && SystemClock.uptimeMillis() - backAt < SLIDE) {
            SystemClock.sleep(10);
        }

        if (!keyboardUp()) {
            return true;
        }

        boolean pressed = send(KeyEvent.ACTION_DOWN, KeyEvent.KEYCODE_BACK, 0) && send(KeyEvent.ACTION_UP, KeyEvent.KEYCODE_BACK, 0);
        backAt = SystemClock.uptimeMillis();

        return pressed;
    }

    private boolean keyboardUp() {
        for (AccessibilityWindowInfo window : automation.getWindows()) {
            if (window.getType() == AccessibilityWindowInfo.TYPE_INPUT_METHOD) {
                return true;
            }
        }

        return false;
    }

    private boolean send(int action, int keyCode, int meta) {
        long now = SystemClock.uptimeMillis();
        KeyEvent event = new KeyEvent(now, now, action, keyCode, 0, meta, KeyCharacterMap.VIRTUAL_KEYBOARD, 0, 0, InputDevice.SOURCE_KEYBOARD);

        if (inject != null) {
            try {
                return (Boolean) inject.invoke(manager, event, WAIT_FOR_FINISH);
            } catch (Exception exception) {
                inject = null;
            }
        }

        return automation.injectInputEvent(event, true);
    }

    /**
     * Part of a text: one character and the key events that type it, or characters to
     * paste, with no keys.
     */
    private static final class Piece {
        final String text;

        final KeyEvent[] keys;

        Piece(String text, KeyEvent[] keys) {
            this.text = text;
            this.keys = keys;
        }
    }
}
