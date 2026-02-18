(function () {
  'use strict';

  const sameAsCompanyShift = document.getElementById('mcch_same_as_company_shift');
  const companyStartTime = document.getElementById('mcch_company_start_time');
  const companyEndTime = document.getElementById('mcch_company_end_time');
  const actualStartTime = document.getElementById('mcch_actual_start_time');
  const actualEndTime = document.getElementById('mcch_actual_end_time');

  if (!sameAsCompanyShift || !companyStartTime || !companyEndTime || !actualStartTime || !actualEndTime) {
    return;
  }

  const syncActualWithCompany = () => {
    actualStartTime.value = companyStartTime.value;
    actualEndTime.value = companyEndTime.value;
  };

  const toggleActualFields = () => {
    const isChecked = sameAsCompanyShift.checked;

    if (isChecked) {
      syncActualWithCompany();
    }

    actualStartTime.readOnly = isChecked;
    actualEndTime.readOnly = isChecked;
  };

  sameAsCompanyShift.addEventListener('change', toggleActualFields);

  companyStartTime.addEventListener('change', () => {
    if (sameAsCompanyShift.checked) {
      actualStartTime.value = companyStartTime.value;
    }
  });

  companyEndTime.addEventListener('change', () => {
    if (sameAsCompanyShift.checked) {
      actualEndTime.value = companyEndTime.value;
    }
  });

  toggleActualFields();
})();
