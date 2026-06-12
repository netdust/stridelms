# Course Catalog UX Design

> **Decision:** Split navigation by learning format, not filters within a single catalog
> **Pattern:** Trajecten | Klassikaal | Online as primary navigation entries

---

## Research Summary

### Why Split Navigation, Not Filters

From [Baymard Institute (2025)](https://baymard.com/blog/ecommerce-navigation-best-practice):

> When product type attributes **aren't shared**, then the product types can be implemented as **separate subcategories**. For instance, "Sofas" have attributes like "Number of Seats", "Modularity", etc., while "Armchairs" have attributes like "Reclinable" and "Swivel" — justifying implementing them as separate categories.

**Applied to Stride:**

| Format | Unique Attributes | Shared with Others? |
|--------|-------------------|---------------------|
| Trajecten | enrollment_deadline, elective_choices, cohort, pick_count | No |
| Klassikaal | venue, sessions, capacity, dates, attendance | No |
| Online | self-paced, instant access, progress tracking | No |

These formats have fundamentally different data models and user flows. Forcing them into one filterable catalog creates:
- Cognitive overload (too many filter options)
- Irrelevant filters (location filter for e-learning?)
- Mixed card layouts (editions show dates, online shows duration)

### Platform Patterns

**Coursera** ([source](https://www.justinmind.com/ui-design/how-to-design-e-learning-platform)):
- Primary nav: Degrees | Professional Certificates | Courses
- Clean design with filters *within* each category type
- Personalized recommendations prominent

**LinkedIn Learning** ([source](https://www.eleken.co/blog-posts/elearning-interface-design-examples)):
- Categories by skill type, not format (Business, Technology, Creative)
- Format is secondary (courses, learning paths, certifications)

**VAD Vormingen v3** (current production):
- Primary nav: Trajecten | Klassikaal | E-learning
- Each section shows courses of that format only
- Theme filtering within each section
- Proven pattern for this specific user base

### UX Principles Applied

From [Paradiso Solutions](https://www.paradisosolutions.com/blog/designing-an-effective-course-catalog-best-practices/):

1. **Progressive Disclosure**: Don't overwhelm users. Show format choice first, then reveal theme groupings within.

2. **Clear Visual Structure**: Intuitive categories with prominent search within category.

3. **Persona-aware**: Healthcare professionals often know *how* they want to learn before *what* they want to learn.

From [eLearning Industry](https://elearningindustry.com/user-centered-design-for-elearning):

4. **Information Architecture**: Group related content together. Klassikaal courses share scheduling concerns; Online courses share self-paced concerns.

5. **Minimize Cognitive Load**: Fewer choices per screen = faster decisions.

---

## Recommended Structure

### Navigation

```
┌──────────────────────────────────────────────────────────┐
│  Logo    Trajecten    Klassikaal    Online    [Search]   │
└──────────────────────────────────────────────────────────┘
```

### URL Structure

| URL | Content | Template |
|-----|---------|----------|
| `/trajecten/` | Trajectory catalog | `archive-vad_trajectory.php` (exists) |
| `/klassikaal/` | Editions grouped by theme | `page-klassikaal.php` (new) |
| `/online/` | Online courses grouped by theme | `page-online.php` (new) |

### Page Layout Pattern

Each catalog page follows the same structure:

```
┌─────────────────────────────────────────────────────────┐
│ Hero: Page title + subtitle                             │
├─────────────────────────────────────────────────────────┤
│ Theme tabs (optional): Alle | Ouderenzorg | GGZ | ...   │
├─────────────────────────────────────────────────────────┤
│ Course grid (cards appropriate to format)               │
│                                                         │
│ ┌─────────┐ ┌─────────┐ ┌─────────┐                    │
│ │ Card 1  │ │ Card 2  │ │ Card 3  │                    │
│ └─────────┘ └─────────┘ └─────────┘                    │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ Pagination (if needed)                                  │
└─────────────────────────────────────────────────────────┘
```

### Card Content by Format

**Klassikaal Edition Card:**
- Course title
- Next date(s)
- Location
- Spots remaining
- Price
- Theme badge

**Online Course Card:**
- Course title
- Duration / modules
- Format badge (E-learning, Webinar)
- Theme badge
- "Start meteen" CTA

**Trajectory Card:**
- Trajectory title
- Status (Open, Lopend, Vol)
- Enrollment deadline
- Number of courses
- Duration

---

## Homepage Integration

Replace "Opleidingen per domein" section with learning mode selector:

```
┌─────────────────────────────────────────────────────────┐
│  Hoe wil je leren?                                      │
├───────────────┬───────────────┬─────────────────────────┤
│               │               │                         │
│  📚 TRAJECT   │  👥 KLASSIKAAL│  🖥️ ONLINE             │
│               │               │                         │
│  Volg een     │  Leer samen   │  Leer op je eigen      │
│  leertraject  │  in de klas   │  tempo                 │
│               │               │                         │
│  X trajecten  │  X edities    │  X cursussen           │
│               │               │                         │
└───────────────┴───────────────┴─────────────────────────┘
```

---

## Implementation Notes

### Taxonomies

The existing taxonomies still apply, but differently:

| Taxonomy | Usage |
|----------|-------|
| `stride_theme` | Group courses within each format page (tabs or sections) |
| `stride_audience` | Optional filter or badge on cards |
| `stride_format` | Determines which page a course appears on |

### Format Taxonomy Values

| Slug | Appears On |
|------|------------|
| `online`, `e-learning` | `/online/` page |
| `webinar` | `/online/` page (live but remote) |
| `classroom`, `klassikaal` | `/klassikaal/` page (shows editions) |
| `blended` | Both pages (or `/klassikaal/` with note) |

### Query Logic

**`/klassikaal/` page:**
```php
// Query editions, not courses directly
$editions = get_posts([
    'post_type' => 'vad_edition',
    'post_status' => 'publish',
    'meta_query' => [
        ['key' => '_ntdst_status', 'value' => ['open', 'few_spots'], 'compare' => 'IN'],
    ],
]);

// Group by theme via parent course taxonomy
foreach ($editions as $edition) {
    $course_id = get_post_meta($edition->ID, '_ntdst_course_id', true);
    $themes = get_the_terms($course_id, 'stride_theme');
}
```

**`/online/` page:**
```php
// Query courses with online-type format
$courses = get_posts([
    'post_type' => 'sfwd-courses',
    'post_status' => 'publish',
    'tax_query' => [
        ['taxonomy' => 'stride_format', 'field' => 'slug', 'terms' => ['online', 'e-learning', 'webinar']],
    ],
]);
```

### Removing Old Catalog

The combined `/opleidingen/` catalog with filters should be:
1. Redirected to `/klassikaal/` (or homepage)
2. Or kept as a "search all" page with minimal UI

---

## Sources

- [Baymard Institute - Navigation Best Practices 2025](https://baymard.com/blog/ecommerce-navigation-best-practice)
- [Paradiso Solutions - Course Catalog Best Practices](https://www.paradisosolutions.com/blog/designing-an-effective-course-catalog-best-practices/)
- [Justinmind - E-learning Platform Design Guide](https://www.justinmind.com/ui-design/how-to-design-e-learning-platform)
- [Eleken - eLearning Interface Design Examples](https://www.eleken.co/blog-posts/elearning-interface-design-examples)
- [eLearning Industry - User-Centered Design](https://elearningindustry.com/user-centered-design-for-elearning)
- [NeuronUX - LMS UX Strategies](https://www.neuronux.com/post/top-7-ux-design-strategies-to-enhance-your-lms)
