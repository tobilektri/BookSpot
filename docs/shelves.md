# Shelves & compartments

[← README](../README.md)

Real shelves are rarely a neat grid: a wide middle section, a narrow column next to the door, one
compartment that only fits paperbacks. BookSpot models exactly that.

## The model

```
Shelf  “Living room”                       (name, room, description)
 ├─ Column “L”   → compartments L1 L2 L3 L4
 ├─ Column “M”   → compartments M1 M2 M3 M4 M5
 └─ Column “R”   → compartments R1 R2 R3
```

- A shelf has any number of **columns**, each column any number of **compartments**.
- Compartments have **names** (`L2`, `Top`, `Kids` …). The names are what you see everywhere and what
  the [CSV import](import-export.md) matches location codes against.
- A book is in exactly one place: shelf + column + compartment – or only on a shelf (“somewhere in
  the hallway shelf”), or nowhere yet (filter *No location*).
- Loans (library or from friends) have no shelf position.

## Shelf view

<img src="images/shelf-en.png" alt="Shelf view" width="720">

Every compartment shows the spines of its books and their number. Click a compartment to see its
books; select several books to **move** them to another compartment at once.

## Editing: drag & drop

<img src="images/shelf-editor-en.gif" alt="Shelf editor" width="720">

*Shelves → (shelf) → Edit* opens the editor:

- **Drag compartments** by their handle – within a column or into another column. **Drag columns**
  to reorder them.
- **The books move along**: a compartment keeps its books wherever you put it; saving updates their
  positions.
- **Keyboard**: focus a handle and use the arrow keys.
- *Add compartment*, *Reverse order* (useful when the import guessed top/bottom the wrong way round),
  *Auto-name compartments* (`L1 … L4`), remove a compartment (its books become “no location”).
- Columns can be renamed and deleted; deleting a whole shelf keeps the books (without location).

Why this matters: the CSV import only knows codes like `L1` … `L4` and has to guess the layout. If in
reality `L1` is at the bottom or `M1` belongs to the right column, fix it here in a few drags.

## Creating a location on the fly

Wherever you choose a location (adding a book, *Move to …*, batch scanning) there is **New location**:

- **New compartment** in an existing shelf and column – the name continues the column's numbering (`L9 → L10`).
- **New shelf** with name, room, number of columns and compartments per column (named `A1`, `A2` … automatically).

The new location is selected right away.

## Location codes from the CSV import

When importing, location codes from the *Location* column or the *Notes* (e.g. `L2`, `M4`, `R3`) are
matched against compartment names (case-insensitive; `Shelf/Compartment` disambiguates when two
shelves have a compartment with the same name). With **“Create unknown locations as a new shelf”** the
import builds a shelf from the codes it finds: letters become columns, numbers compartments
(`L1 L2 M1 M3 R1` → columns L, M, R; missing numbers such as `M2` are filled in). Afterwards, check the arrangement in the editor.
See [import-export.md](import-export.md).
