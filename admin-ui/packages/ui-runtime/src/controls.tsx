import { useEffect, useRef } from 'react';
/** Binary switches and three-state permission groups share the template's visual control. */
export function Toggle({
  label,
  checked,
  mixed = false,
  group = false,
  disabled = false,
  onChange,
}: {
  label: string;
  checked: boolean;
  mixed?: boolean;
  group?: boolean;
  disabled?: boolean;
  onChange: () => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (input.current) input.current.indeterminate = mixed;
  }, [mixed]);
  return (
    <input
      ref={input}
      type="checkbox"
      role={group ? 'checkbox' : 'switch'}
      className="design-toggle"
      aria-label={label}
      aria-checked={mixed ? 'mixed' : checked}
      data-mixed={mixed || undefined}
      checked={checked}
      disabled={disabled}
      onChange={onChange}
    />
  );
}
