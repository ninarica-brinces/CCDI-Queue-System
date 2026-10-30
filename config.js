export const APP_CONFIG = {
  apiUrl: './api.php', // API is served with the app locally and on Render.
  lanes: [
    { id: 'Regular', label: 'Regular Lane', prefix: 'A' },
    { id: 'Priority', label: 'Priority Lane (PWD / Senior / Parents)', prefix: 'P' }
  ],
  services: [
    { id: 'tuition', label: 'Tuition Payment' },
    { id: 'cert_enrollment', label: 'Cert. of Enrollment' },
    { id: 'cert_registration', label: 'Cert. of Registration' },
    { id: 'transcript', label: 'Transcript of Records (TOR)' },
    { id: 'clearance', label: 'Student Clearance' }
  ],
  paymentOptions: [
    { id: 'full', label: 'Full Payment' },
    { id: 'partial_50', label: 'Partial Payment (50%)' },
    { id: 'partial_25', label: 'Partial Payment (25%)' },
    { id: 'promissory', label: 'Promissory Note' },
    { id: 'none', label: 'N/A (Document Only)' }
  ]
};