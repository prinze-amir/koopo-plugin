## Re-alignment: The 13-Phase Roadmap (locked)

Here is the **official project roadmap**, unchanged, now aligned to commits.

### **Phase 0 – Foundation & Stability** *(IN PROGRESS / nearly done)*

1. Plugin bootstrap stability
2. Admin menu architecture
3. Widget rendering correctness
4. Load order safety
5. No stray output / fatals

👉 **Commits:** 001–003
✅ Almost complete

---

### **Phase 1 – Stories Core Data Model**

6. Story CPT (`koopo_story`)
7. Story Item CPT (`koopo_story_item`)
8. Meta schema + expiration model
9. Ownership + permissions rules

👉 **Next commits:** 004–006

---

### **Phase 2 – Seen / View Tracking**

10. Custom DB table (`story_views`)
11. Insert + lookup logic
12. Unseen detection logic

👉 **Commits:** 007–008

---

### **Phase 3 – REST API (API-First)**

13. Feed endpoint
14. Story detail endpoint
15. Seen endpoint
16. Upload endpoint
17. Permission callbacks

👉 **Commits:** 009–011

---

### **Phase 4 – Web UI: Stories Tray**

18. Tray renderer
19. Unseen ring logic
20. Ordering (unseen / recent)
21. Widget + shortcode integration

👉 **Commits:** 012–014

---

### **Phase 5 – Web UI: Viewer**

22. Fullscreen modal
23. Progress bars
24. Tap / swipe navigation
25. Autoplay rules

👉 **Commits:** 015–017

---

### **Phase 6 – Uploader UX**

26. Preview before upload
27. Rate limiting
28. Validation
29. Error handling

👉 **Commits:** 018–019

---

### **Phase 7 – Cleanup & Expiration**

30. Cron cleanup
31. Manual admin cleanup
32. Orphan detection

👉 **Commit:** 020

---

### **Phase 8 – Widget Enhancements**

33. Layout modes
34. Per-widget overrides
35. Sidebar UX polish

👉 **Commits:** 021–022

---

### **Phase 9 – Moderation**

36. Reporting
37. Admin review
38. Auto-hide thresholds

👉 **Commit:** 023

---

### **Phase 10 – Performance**

39. Caching
40. Query optimization
41. Lazy loading

👉 **Commit:** 024

---

### **Phase 11 – React Native Readiness**

42. Auth abstraction
43. Mobile-friendly payloads
44. API versioning

👉 **Commit:** 025

---

### **Phase 12 – Final Polish**

45. Accessibility
46. Animations
47. Edge cases

👉 **Commit:** 026

---

### **Phase 13 – Hardening & Release**

48. Security review
49. Back-compat
50. Release notes

👉 **Commit:** 027 (v1.0)

---

## Process change (important)

Starting **Commit 004**, every commit will include:

* ✅ **Phase number(s)** in the commit notes
* ✅ **Which checklist items were completed**
* ✅ **Which phase is next**

Example:



