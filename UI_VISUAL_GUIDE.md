# UI Changes Visual Guide

## Button Layout

The new "Schedule scan in 3 minutes" button appears directly next to the existing "Launch scan now" button:

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│  [Personnaliser le prochain scan]                               │
│                                                                  │
│  ┌───────────────────────────┐  ┌────────────────────────────┐  │
│  │ Lancer un scan maintenant │  │ Lancer le scan dans       │  │
│  │     (default style)       │  │    3 minutes              │  │
│  └───────────────────────────┘  │  (orange/yellow style)    │  │
│                                  └────────────────────────────┘  │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

### Button Styles

**"Lancer un scan maintenant" (existing button):**
- Default button styling from the application
- Standard button appearance

**"Lancer le scan dans 3 minutes" (new button):**
- Background: `#f39c12` (orange/yellow)
- Border: `#e67e22` (darker orange)
- Visually distinct from the immediate scan button
- Same height and font size as the existing button
- Side-by-side layout using flexbox

## Success Message

After clicking the "Schedule scan in 3 minutes" button, a success message appears:

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│  ✓ Scan programmé avec succès pour le 2025-11-21 16:03:27      │
│    (dans 3 minutes)                                             │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

**Success Message Styles:**
- Background: `#d5f4e6` (light green)
- Text color: `#27ae60` (dark green)
- Border: `1px solid #27ae60`
- Border radius: `5px`
- Padding: `10px`
- Font weight: bold
- Margin bottom: `1em`

## User Flow

### Step 1: Initial State
User sees the client page with both buttons displayed side by side.

### Step 2: Click "Lancer le scan dans 3 minutes"
User clicks the orange/yellow button.

### Step 3: Confirmation
Page reloads with success message showing the scheduled time (approximately 3 minutes in the future).

### Step 4: Execution
After 3 minutes, the cron job (`cron_scanner.sh`) executes the scheduled scan.

### Step 5: Results
Scan results appear in the calendar and scan results section, just like an immediate scan.

## Layout Context

The buttons appear in the following context on the page:

```
┌────────────────────────────────────────────┐
│  Client: [Client Name]                     │
│                                            │
│  📅 Calendrier des scans                   │
│  [Calendar widget]                         │
│                                            │
│  🔧 Paramètres assets & outils             │
│  [Collapsible section]                     │
│                                            │
│  📊 Planification des scans                │
│  [Frequency settings]                      │
│                                            │
│  [Personnaliser le prochain scan]          │
│                                            │
│  [Lancer un scan maintenant] [Lancer le   │
│                               scan dans    │
│                               3 minutes]   │
│  ← Two buttons side by side               │
│                                            │
│  [Success message if scheduled]            │
│                                            │
│  📈 Scan results...                        │
└────────────────────────────────────────────┘
```

## Color Scheme

The color scheme follows the application's existing design:

- **Immediate scan**: Default button colors (matching existing UI)
- **Scheduled scan**: Warm orange/yellow (`#f39c12`, `#e67e22`)
- **Success message**: Green (`#27ae60`, `#d5f4e6`)
- **Error/Alert**: (not implemented, but could use red if needed)

The orange/yellow color was chosen to:
1. Differentiate from the immediate scan button
2. Suggest a "warning" or "future action" (not immediate)
3. Remain visually appealing and consistent with the app's design

## Responsive Design

The buttons use flexbox with `gap:10px`, which provides:
- Automatic spacing between buttons
- Responsive layout (will stack on very small screens if needed)
- Clean, modern appearance
- Easy to extend with additional buttons in the future

## Accessibility

Both buttons:
- Have clear, descriptive text in French
- Use standard HTML form elements
- Maintain consistent styling
- Provide visual feedback (success message)
- Are keyboard accessible (standard form submit)

## Code Location

The button implementation can be found at:
- **File**: `client.php`
- **Lines**: 849-858 (buttons)
- **Lines**: 861-867 (success message)
