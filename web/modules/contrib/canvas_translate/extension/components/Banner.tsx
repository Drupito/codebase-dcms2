import { Callout } from '@radix-ui/themes';
import { ExclamationTriangleIcon, InfoCircledIcon } from '@radix-ui/react-icons';

type Tone = 'error' | 'warning' | 'info' | 'neutral';

const toneColor: Record<Tone, 'red' | 'amber' | 'blue' | 'gray'> = {
  error: 'red',
  warning: 'amber',
  info: 'blue',
  neutral: 'gray',
};

/**
 * Inline message banner — Radix Callout, so every surface (dashboard, editor,
 * review) reports problems with Canvas's own treatment.
 */
export function Banner({
  children,
  tone = 'error',
  style,
}: {
  children: React.ReactNode;
  tone?: Tone;
  style?: React.CSSProperties;
}) {
  const showIcon = tone === 'error' || tone === 'warning' || tone === 'info';
  return (
    <Callout.Root
      color={toneColor[tone]}
      size="1"
      role={tone === 'error' ? 'alert' : 'status'}
      style={style}
    >
      {showIcon && (
        <Callout.Icon>
          {tone === 'info' ? <InfoCircledIcon /> : <ExclamationTriangleIcon />}
        </Callout.Icon>
      )}
      <Callout.Text>{children}</Callout.Text>
    </Callout.Root>
  );
}
