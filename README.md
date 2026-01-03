## Koopo Stories: 17-Phase Development Roadmap

Here is the **official project roadmap** for the Stories feature, aligned to commits and enhanced with social engagement features.

---

## 📊 Current Status

| Phase | Status | Completion |
|-------|--------|------------|
| **Phase 0-8** | ✅ Complete | 100% |
| **Phase 9-17** | 🔄 Planned | 0% |

**Overall Progress:** 47% (8/17 phases complete)

**Latest Commit:** 004 - Added profile URL support and current user avatar

**Next Up:** Phase 9 - User Privacy & Granular Controls

---

### **Phase 0 – Foundation & Stability** ✅ **COMPLETE**

1. Plugin bootstrap stability
2. Admin menu architecture
3. Widget rendering correctness
4. Load order safety
5. No stray output / fatals

👉 **Commits:** 001–003
✅ Almost complete

---

### **Phase 1 – Stories Core Data Model** ✅ **COMPLETE**

6. Story CPT (`koopo_story`)
7. Story Item CPT (`koopo_story_item`)
8. Meta schema + expiration model
9. Ownership + permissions rules

👉 **Commits:** 001–003
✅ All CPTs, meta, and permissions implemented

---

### **Phase 2 – Seen / View Tracking** ✅ **COMPLETE**

10. Custom DB table (`story_views`)
11. Insert + lookup logic
12. Unseen detection logic

👉 **Commits:** 001–003
✅ Custom table with view tracking complete

---

### **Phase 3 – REST API (API-First)** ✅ **COMPLETE**

13. Feed endpoint
14. Story detail endpoint
15. Seen endpoint
16. Upload endpoint
17. Permission callbacks

👉 **Commits:** 001–003
✅ All 4 REST endpoints implemented

---

### **Phase 4 – Web UI: Stories Tray** ✅ **COMPLETE**

18. Tray renderer
19. Unseen ring logic
20. Ordering (unseen / recent)
21. Widget + shortcode integration

👉 **Commits:** 001–003
✅ Tray, widget, and shortcode complete

---

### **Phase 5 – Web UI: Viewer** ✅ **COMPLETE**

22. Fullscreen modal
23. Progress bars
24. Tap / swipe navigation
25. Autoplay rules

👉 **Commits:** 001–003
✅ Fullscreen viewer with navigation complete

---

### **Phase 6 – Uploader UX** ✅ **COMPLETE**

26. Preview before upload
27. Rate limiting
28. Validation
29. Error handling

👉 **Commits:** 001–003
✅ Upload composer with preview complete

---

### **Phase 7 – Cleanup & Expiration** ✅ **COMPLETE**

30. Cron cleanup
31. Manual admin cleanup
32. Orphan detection

👉 **Commits:** 001–003
✅ Automated cron cleanup implemented

---

### **Phase 8 – Widget Enhancements** ✅ **COMPLETE**

33. Layout modes
34. Per-widget overrides
35. Sidebar UX polish

👉 **Commits:** 001–003
✅ Horizontal/vertical layouts complete

---

### **Phase 9 – User Privacy & Granular Controls**

36. Per-story privacy settings (public, friends only, custom lists)
37. Story archive (save stories beyond 24h for logged-in user)
38. Hide story from specific users
39. Close friends list integration

👉 **Commits:** 023–024

---

### **Phase 10 – Engagement: Reactions & Replies**

40. Like/reaction system (emoji picker)
41. Story replies (DM or comment system)
42. Reaction counts display
43. Reply notifications (BuddyBoss notifications integration)

👉 **Commits:** 025–026

---

### **Phase 11 – Interactive Features**

44. Mentions (@username) with autocomplete
45. Link sticker (attach URL to story)
46. Location tag integration (optional)
47. Poll sticker (vote on story)

👉 **Commits:** 027–028

---

### **Phase 12 – Analytics & Insights**

48. View counts per story
49. Viewer list ("Seen by" feature)
50. Per-user "seen" state tracking
51. Story insights dashboard (who viewed, when)

👉 **Commits:** 029–030

---

### **Phase 13 – Moderation**

52. Reporting
53. Admin review dashboard
54. Auto-hide thresholds
55. Flagged content queue

👉 **Commit:** 031

---

### **Phase 14 – Performance**

56. Caching (transients for feeds)
57. Query optimization
58. Lazy loading for media
59. CDN integration for attachments

👉 **Commit:** 032

---

### **Phase 15 – React Native Readiness**

60. Auth abstraction
61. Mobile-friendly payloads
62. API versioning
63. Push notification hooks

👉 **Commit:** 033

---

### **Phase 16 – Final Polish**

64. Accessibility (ARIA labels, keyboard nav)
65. Animations & transitions
66. Edge cases & error handling
67. Internationalization (i18n)

👉 **Commit:** 034

---

### **Phase 17 – Hardening & Release**

68. Security review
69. Back-compat testing
70. Release notes
71. Documentation

👉 **Commit:** 035 (v1.0)

---

## Process change (important)

Starting **Commit 004**, every commit will include:

* ✅ **Phase number(s)** in the commit notes
* ✅ **Which checklist items were completed**
* ✅ **Which phase is next**

Example:
```
Commit 004: Phase 0 enhancements
- Added BuddyBoss profile URL linking
- Fixed current user avatar display
Phase 0 complete, moving to Phase 9
```

---

## 📋 Feature Comparison: Planned vs. Industry Standard

| Feature | Instagram | Facebook | Koopo Stories (Planned) |
|---------|-----------|----------|-------------------------|
| **Core Features** |
| 24h auto-expire | ✅ | ✅ | ✅ Complete |
| Image/Video upload | ✅ | ✅ | ✅ Complete |
| Fullscreen viewer | ✅ | ✅ | ✅ Complete |
| Progress bars | ✅ | ✅ | ✅ Complete |
| **Privacy** |
| Public/Friends toggle | ✅ | ✅ | 🔄 Phase 9 |
| Close friends list | ✅ | ✅ | 🔄 Phase 9 |
| Hide from specific users | ✅ | ✅ | 🔄 Phase 9 |
| Story archive | ✅ | ✅ | 🔄 Phase 9 |
| **Engagement** |
| Reactions/Likes | ✅ | ✅ | 🔄 Phase 10 |
| DM replies | ✅ | ✅ | 🔄 Phase 10 |
| View counts | ✅ | ✅ | 🔄 Phase 12 |
| Viewer list | ✅ | ✅ | 🔄 Phase 12 |
| **Interactive** |
| Mentions | ✅ | ✅ | 🔄 Phase 11 |
| Link stickers | ✅ | ✅ | 🔄 Phase 11 |
| Location tags | ✅ | ✅ | 🔄 Phase 11 |
| Polls | ✅ | ✅ | 🔄 Phase 11 |
| **Platform** |
| Web support | ✅ | ✅ | ✅ Complete |
| Mobile app API | ✅ | ✅ | 🔄 Phase 15 |
| Push notifications | ✅ | ✅ | 🔄 Phase 15 |

---

## 🎯 Development Priorities

### **Immediate (Next 2 weeks)**
1. **Phase 9:** Privacy controls (essential for user trust)
2. **Phase 10:** Reactions & replies (drives engagement)

### **Short-term (1 month)**
3. **Phase 12:** Analytics/insights (user value)
4. **Phase 13:** Moderation tools (platform safety)

### **Medium-term (2-3 months)**
5. **Phase 11:** Interactive stickers
6. **Phase 14:** Performance optimization
7. **Phase 15:** Mobile API readiness

### **Long-term (3+ months)**
8. **Phase 16:** Polish & accessibility
9. **Phase 17:** Security audit & v1.0 release

---

## 📝 Notes

- Privacy features (Phase 9) are **critical** before public launch
- Engagement features (Phase 10) should come before analytics
- Performance optimization (Phase 14) can run parallel with feature development
- All phases maintain backward compatibility with existing stories

