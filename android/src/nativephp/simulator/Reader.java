package nativephp.simulator;

import android.app.UiAutomation;
import android.graphics.Point;
import android.graphics.Rect;
import android.util.Xml;
import android.view.Display;
import android.view.accessibility.AccessibilityNodeInfo;
import android.view.accessibility.AccessibilityNodeInfo.AccessibilityAction;

import org.xmlpull.v1.XmlSerializer;

import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.io.PrintStream;
import java.lang.reflect.Method;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeoutException;

/**
 * Reads the screen for AndroidDriver without starting a process per read.
 *
 * `uiautomator dump` starts app_process, connects to UiAutomation, waits for the screen to
 * be idle for a whole second, writes one dump, and exits: about two seconds a read. This
 * connects once, the same way (through the device's own uiautomator.jar), and answers a
 * "dump" line on stdin with a length line and the XML on stdout, until stdin closes.
 *
 * It waits for the same second without an accessibility event, at most ten, as uiautomator
 * does. That second costs nothing on a screen that has been still for it. A fresh
 * connection always paid it, because connecting sets apps sending events.
 *
 * The XML is the one `uiautomator dump` writes: the active window's nodes, children the
 * user cannot see left out, and bounds cut to the display. Every node is fetched fresh
 * from the app, as a new `uiautomator dump` process would. A scrollable node also says
 * which way it scrolls (scroll-axis), from the scroll actions it offers, which the plain
 * dump leaves out. The hierarchy carries the display's width and height.
 *
 * Run as: CLASSPATH=/system/framework/uiautomator.jar:<this jar> app_process /system/bin nativephp.simulator.Reader [idle ms] [timeout ms]
 */
public final class Reader {
    private Reader() {
    }

    public static void main(String[] args) throws Exception {
        long idle = args.length > 0 ? Long.parseLong(args[0]) : 1000;
        long timeout = args.length > 1 ? Long.parseLong(args[1]) : 10000;

        Class<?> wrapperClass = Class.forName("com.android.uiautomator.core.UiAutomationShellWrapper");
        Object wrapper = wrapperClass.getConstructor().newInstance();
        wrapperClass.getMethod("connect").invoke(wrapper);
        wrapperClass.getMethod("setCompressedLayoutHierarchy", boolean.class).invoke(wrapper, false);
        UiAutomation automation = (UiAutomation) wrapperClass.getMethod("getUiAutomation").invoke(wrapper);

        PrintStream out = new PrintStream(System.out, false, "UTF-8");
        BufferedReader in = new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
        out.print("ready\n");
        out.flush();

        try {
            String line;

            while ((line = in.readLine()) != null) {
                if (!line.trim().equals("dump")) {
                    continue;
                }

                byte[] xml;

                try {
                    xml = dump(automation, idle, timeout);
                } catch (Exception exception) {
                    xml = null;
                }

                if (xml == null) {
                    out.print("-1\n");
                } else {
                    out.print(xml.length + "\n");
                    out.write(xml);
                }

                out.flush();
            }
        } finally {
            wrapperClass.getMethod("disconnect").invoke(wrapper);
        }
    }

    private static byte[] dump(UiAutomation automation, long idle, long timeout) throws Exception {
        try {
            automation.waitForIdle(idle, timeout);
        } catch (TimeoutException ignored) {
            // A screen that never stops moving is read as it is.
        }

        AccessibilityNodeInfo root = automation.getRootInActiveWindow();

        if (root == null || !root.refresh()) {
            return null;
        }

        Display display = display();
        Point size = new Point();
        display.getRealSize(size);

        ByteArrayOutputStream bytes = new ByteArrayOutputStream();
        XmlSerializer serializer = Xml.newSerializer();
        serializer.setOutput(bytes, "UTF-8");
        serializer.startDocument("UTF-8", true);
        serializer.startTag("", "hierarchy");
        serializer.attribute("", "rotation", Integer.toString(display.getRotation()));
        serializer.attribute("", "width", Integer.toString(size.x));
        serializer.attribute("", "height", Integer.toString(size.y));
        node(root, serializer, 0, size.x, size.y);
        serializer.endTag("", "hierarchy");
        serializer.endDocument();

        return bytes.toByteArray();
    }

    private static Display display() throws Exception {
        Class<?> managerClass = Class.forName("android.hardware.display.DisplayManagerGlobal");
        Object manager = managerClass.getMethod("getInstance").invoke(null);
        Method real = managerClass.getMethod("getRealDisplay", int.class);

        return (Display) real.invoke(manager, Display.DEFAULT_DISPLAY);
    }

    private static void node(AccessibilityNodeInfo node, XmlSerializer serializer, int index, int width, int height) throws Exception {
        serializer.startTag("", "node");
        serializer.attribute("", "index", Integer.toString(index));
        serializer.attribute("", "text", text(node.getText()));
        serializer.attribute("", "resource-id", text(node.getViewIdResourceName()));
        serializer.attribute("", "class", text(node.getClassName()));
        serializer.attribute("", "package", text(node.getPackageName()));
        serializer.attribute("", "content-desc", text(node.getContentDescription()));
        serializer.attribute("", "checkable", Boolean.toString(node.isCheckable()));
        serializer.attribute("", "checked", Boolean.toString(node.isChecked()));
        serializer.attribute("", "clickable", Boolean.toString(node.isClickable()));
        serializer.attribute("", "enabled", Boolean.toString(node.isEnabled()));
        serializer.attribute("", "focusable", Boolean.toString(node.isFocusable()));
        serializer.attribute("", "focused", Boolean.toString(node.isFocused()));
        serializer.attribute("", "scrollable", Boolean.toString(node.isScrollable()));

        if (node.isScrollable()) {
            serializer.attribute("", "scroll-axis", axis(node));
        }

        serializer.attribute("", "long-clickable", Boolean.toString(node.isLongClickable()));
        serializer.attribute("", "password", Boolean.toString(node.isPassword()));
        serializer.attribute("", "selected", Boolean.toString(node.isSelected()));
        serializer.attribute("", "bounds", bounds(node, width, height).toShortString());
        serializer.attribute("", "drawing-order", Integer.toString(node.getDrawingOrder()));
        serializer.attribute("", "hint", text(node.getHintText()));

        for (int i = 0; i < node.getChildCount(); i++) {
            AccessibilityNodeInfo child = node.getChild(i);

            // A node from the connection's cache can be stale: the cache is only cleared by
            // the events an app sends, and Compose sends them in batches, so a row still
            // gliding could read twice in the same place. A fresh process, like
            // `uiautomator dump`, has no cache to read from.
            if (child != null && child.refresh() && child.isVisibleToUser()) {
                node(child, serializer, i, width, height);
            }
        }

        serializer.endTag("", "node");
    }

    /**
     * Compose and the platform's scroll views offer scroll left and right on a node that
     * scrolls sideways, and scroll up and down on one that scrolls down the screen.
     */
    private static String axis(AccessibilityNodeInfo node) {
        boolean sideways = false;
        boolean down = false;

        for (AccessibilityAction action : node.getActionList()) {
            int id = action.getId();

            if (id == AccessibilityAction.ACTION_SCROLL_LEFT.getId() || id == AccessibilityAction.ACTION_SCROLL_RIGHT.getId()) {
                sideways = true;
            }

            if (id == AccessibilityAction.ACTION_SCROLL_UP.getId() || id == AccessibilityAction.ACTION_SCROLL_DOWN.getId()) {
                down = true;
            }
        }

        if (sideways == down) {
            return "";
        }

        return sideways ? "horizontal" : "vertical";
    }

    private static Rect bounds(AccessibilityNodeInfo node, int width, int height) {
        Rect bounds = new Rect();
        node.getBoundsInScreen(bounds);

        return bounds.intersect(new Rect(0, 0, width, height)) ? bounds : new Rect();
    }

    /**
     * The characters uiautomator drops from a dump, because XML 1.0 cannot carry them.
     */
    private static String text(CharSequence value) {
        if (value == null) {
            return "";
        }

        StringBuilder text = new StringBuilder(value.length());

        for (int i = 0; i < value.length(); i++) {
            char c = value.charAt(i);

            if ((c >= 0x1 && c <= 0x8) || (c >= 0xB && c <= 0xC) || (c >= 0xE && c <= 0x1F)
                    || (c >= 0x7F && c <= 0x84) || (c >= 0x86 && c <= 0x9F)
                    || (c >= 0xFDD0 && c <= 0xFDDF) || c == 0xFFFE || c == 0xFFFF) {
                text.append('.');
            } else {
                text.append(c);
            }
        }

        return text.toString();
    }
}
