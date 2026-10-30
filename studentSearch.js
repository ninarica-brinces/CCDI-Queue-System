import { APP_CONFIG } from './config.js';

export function setupStudentSearch(input, results, onSelect) {
  if (!input || !results) return;

  let debounceTimer;
  let controller;

  input.addEventListener('input', () => {
    const query = input.value.trim();
    clearTimeout(debounceTimer);
    if (controller) controller.abort();

    if (query.length < 2) {
      results.replaceChildren();
      results.hidden = true;
      return;
    }

    debounceTimer = setTimeout(async () => {
      controller = new AbortController();
      results.replaceChildren();
      results.hidden = false;
      results.textContent = 'Searching...';

      try {
        const url = new URL(APP_CONFIG.apiUrl, window.location.href);
        url.searchParams.set('action', 'search_students');
        url.searchParams.set('q', query);
        const response = await fetch(url, {
          cache: 'no-store',
          signal: controller.signal
        });
        const payload = await response.json();
        if (!response.ok || !payload.success || !Array.isArray(payload.data)) {
          throw new Error(payload.message || 'Student search failed');
        }

        results.replaceChildren();
        if (payload.data.length === 0) {
          results.textContent = 'No matching students.';
          return;
        }

        payload.data.forEach(student => {
          const option = document.createElement('button');
          option.type = 'button';
          option.className = 'student-search-option';
          option.setAttribute('role', 'option');
          const title = document.createElement('strong');
          title.textContent = `${student.student_id} - ${student.full_name}`;
          const details = document.createElement('small');
          details.textContent = `${student.course} | ${student.address}`;
          option.append(title, details);
          option.addEventListener('click', () => {
            input.value = student.student_id;
            results.replaceChildren();
            results.hidden = true;
            onSelect(student);
          });
          results.appendChild(option);
        });
      } catch (error) {
        if (error.name === 'AbortError') return;
        console.error('Student search failed:', error);
        results.replaceChildren();
        results.textContent = 'Student search is unavailable. Please try again.';
      }
    }, 200);
  });

  document.addEventListener('click', event => {
    if (!input.contains(event.target) && !results.contains(event.target)) {
      results.hidden = true;
    }
  });
}
